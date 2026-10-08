<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->owner = User::factory()->create(['password' => 'MotDePasse-2026!']);
        $this->actingAs($this->owner);
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913'])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_production_always_uses_https(): void
    {
        $this->app['env'] = 'production';
        $this->get('http://localhost/connexion')->assertStatus(301)->assertRedirect('https://localhost/connexion');
        $this->get('https://localhost/connexion')->assertHeader('Strict-Transport-Security');
    }

    public function test_opening_the_app_gets_a_new_session_id(): void
    {
        $this->post(route('lock'));
        $before = session()->getId();
        $this->post(route('unlock.store'), ['code' => '482913'])->assertRedirect(route('dashboard'));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_ten_wrong_codes_in_a_day_log_out_every_device(): void
    {
        $push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $push);
        $token = $this->owner->remember_token;
        $this->post(route('lock'));

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('unlock.store'), ['code' => '000000']);
        }
        Carbon::setTestNow(now()->addMinutes(16));
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('unlock.store'), ['code' => '000000']);
        }
        $this->assertAuthenticated();
        $this->post(route('unlock.store'), ['code' => '000000']);

        $this->assertGuest();
        $this->assertNotSame($token, $this->owner->fresh()->remember_token);
        $this->assertDatabaseHas('activity_log', ['action' => 'argent.logout_all']);
        $push->shouldHaveReceived('send')->with('Argent : appareils déconnectés', Mockery::any(), Mockery::any(), $this->owner->id)->once();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_export_never_turns_a_label_into_an_excel_formula(): void
    {
        $account = MoneyAccount::query()->where('scope', 'perso')->sole();
        MoneyTransaction::query()->create(['account_id' => $account->id, 'occurred_on' => '2026-10-14', 'amount' => -100, 'kind' => 'expense', 'label' => '=HYPERLINK("http://x","clic")', 'source' => 'import']);

        $csv = $this->get(route('export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
    }

    public function test_lock_when_leaving_the_app_can_be_turned_off(): void
    {
        $this->get(route('dashboard'))->assertSee('data-lock-on-leave="1"', false);
        $this->put(route('settings.update'), ['lock_minutes' => 15])->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertSee('data-lock-on-leave="0"', false);
    }

    public function test_pages_are_private_and_never_cached(): void
    {
        foreach ([route('dashboard'), route('settings'), route('accounts.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        auth()->logout();
        foreach ([route('dashboard'), route('export'), route('settings'), route('backups.download', 'argent-2026-10-14-013000.sqlite')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
