<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\FaceIdService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FaceIdTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!']);
        $this->actingAs($this->owner);
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913'])->assertSessionHasNoErrors();
    }

    private function enableFaceId(): WebauthnCredential
    {
        return WebauthnCredential::query()->create([
            'user_id' => $this->owner->id, 'credential_id' => 'abc123', 'public_key' => '-----BEGIN PUBLIC KEY-----', 'device' => 'iPhone · Safari',
        ]);
    }

    public function test_without_face_id_the_code_alone_opens_the_app(): void
    {
        $this->post(route('lock'));
        $this->get(route('unlock'))->assertOk()->assertSee('Tapez votre code Argent')->assertDontSee('Ouvrir avec Face ID');
        $this->post(route('unlock.store'), ['code' => '482913'])->assertRedirect(route('dashboard'));
    }

    public function test_with_face_id_the_code_needs_the_password_too(): void
    {
        $this->enableFaceId();
        $this->post(route('lock'));
        $this->get(route('unlock'))->assertOk()->assertSee('Ouvrir avec Face ID')->assertSee('Face ID ne marche pas ?');

        $this->post(route('unlock.store'), ['code' => '482913'])->assertSessionHasErrors('password');
        $this->post(route('unlock.store'), ['code' => '482913', 'password' => 'faux'])->assertSessionHasErrors('password');
        $this->get(route('dashboard'))->assertRedirect(route('unlock'));
        $this->post(route('unlock.store'), ['code' => '482913', 'password' => 'MotDePasse-2026!'])->assertRedirect(route('dashboard'));
    }

    public function test_face_id_signature_opens_the_app_and_failures_count(): void
    {
        $this->enableFaceId();
        $this->post(route('lock'));
        $faceId = Mockery::mock(FaceIdService::class)->makePartial();
        $faceId->shouldReceive('unlockOptions')->andReturn(['publicKey' => ['challenge' => 'xyz']]);
        $faceId->shouldReceive('verify')->andReturn(false, false, true);
        $this->app->instance(FaceIdService::class, $faceId);

        $this->postJson(route('unlock.faceid.options'))->assertOk()->assertJsonPath('publicKey.challenge', 'xyz');
        $this->postJson(route('unlock.faceid'), ['id' => 'abc123'])->assertStatus(422)->assertJsonPath('message', 'Face ID non reconnu. Réessayez.');
        $this->postJson(route('unlock.faceid'), ['id' => 'abc123'])->assertStatus(422);
        $this->get(route('dashboard'))->assertRedirect(route('unlock'));
        $this->postJson(route('unlock.faceid'), ['id' => 'abc123'])->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_devices_are_listed_and_can_be_removed(): void
    {
        $credential = $this->enableFaceId();
        $this->get(route('settings'))->assertOk()->assertSee('iPhone · Safari')->assertSee('Activer Face ID sur cet appareil');
        $this->postJson(route('faceid.options'))->assertOk()->assertJsonStructure(['publicKey' => ['challenge', 'rp', 'user']]);

        $this->delete(route('faceid.destroy', $credential))->assertRedirect(route('settings'));
        $this->assertModelMissing($credential);
        $this->assertDatabaseHas('activity_log', ['action' => 'faceid.removed']);
        // Plus de Face ID : le code seul suffit de nouveau.
        $this->post(route('lock'));
        $this->post(route('unlock.store'), ['code' => '482913'])->assertRedirect(route('dashboard'));
    }

    public function test_someone_else_cannot_remove_a_device(): void
    {
        $credential = WebauthnCredential::query()->create([
            'user_id' => User::factory()->create()->id, 'credential_id' => 'other', 'public_key' => 'x',
        ]);
        $this->delete(route('faceid.destroy', $credential))->assertNotFound();
        $this->assertModelExists($credential);
    }

    public function test_login_from_a_new_device_sends_an_alert(): void
    {
        $push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $push);
        auth()->logout();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1')
            ->post(route('login'), ['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!']);
        $push->shouldNotHaveReceived('send');
        auth()->logout();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1')
            ->post(route('login'), ['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!']);
        $push->shouldNotHaveReceived('send');
        auth()->logout();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64) Chrome/130.0')
            ->post(route('login'), ['email' => 'matt@example.com', 'password' => 'MotDePasse-2026!']);
        $push->shouldHaveReceived('send')->with('Nouvelle connexion à Argent', 'Connexion depuis un nouvel appareil (Windows · Chrome). Si ce n\'est pas vous, changez votre mot de passe.', Mockery::any(), $this->owner->id)->once();
    }
}
