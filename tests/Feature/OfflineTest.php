<?php

namespace Tests\Feature;

use App\Http\Controllers\OfflineController;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/** Mode hors ligne : page sans données, activation avec le code, résumé, envoi des mouvements notés sans réseau. */
class OfflineTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'q83vEjRWeJASNFZ4kBI0VniQEjRWeJASNFZ4kBI0Vng=';

    private MoneyAccount $perso;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->app->instance(PushService::class, Mockery::spy(PushService::class));
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '1 000'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('scope', 'perso')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_offline_page_holds_no_data_and_opens_without_login(): void
    {
        auth()->logout();
        $page = $this->get(route('offline'))->assertOk()->assertSee('data-offline-shell', false)->assertSee('Pas de connexion');
        $this->assertStringNotContainsString('Compte perso', $page->getContent());
        $this->assertStringNotContainsString('1 000', $page->getContent());
    }

    public function test_activation_needs_the_code_and_sets_the_device_key(): void
    {
        $this->postJson(route('offline.activate'), ['code' => '000000', 'key' => self::KEY])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Code faux. Encore 4 essai(s).']);
        $this->postJson(route('offline.activate'), ['code' => '482913', 'key' => 'pas-une-cle'])->assertStatus(422);

        $this->postJson(route('offline.activate'), ['code' => '482913', 'key' => self::KEY])
            ->assertOk()->assertJsonStructure(['ok', 'version'])
            ->assertCookie(OfflineController::COOKIE, self::KEY);

        // La clé n'est donnée qu'à l'app déverrouillée.
        $this->withCookie(OfflineController::COOKIE, self::KEY)->get(route('dashboard'))
            ->assertOk()->assertSee('data-key="'.self::KEY.'"', false);
        $this->post(route('lock'));
        $this->withCookie(OfflineController::COOKIE, self::KEY)->get(route('unlock'))
            ->assertOk()->assertDontSee(self::KEY, false);
        $this->withCookie(OfflineController::COOKIE, self::KEY)->get(route('offline'))
            ->assertOk()->assertDontSee(self::KEY, false);
    }

    public function test_deactivation_forgets_the_key(): void
    {
        $this->withCookie(OfflineController::COOKIE, self::KEY)->postJson(route('offline.deactivate'))
            ->assertOk()->assertCookieExpired(OfflineController::COOKIE);
    }

    public function test_summary_for_the_phone(): void
    {
        MoneyTransaction::query()->create(['account_id' => $this->perso->id, 'occurred_on' => '2026-10-14', 'amount' => -4500, 'kind' => 'expense', 'label' => 'Plein', 'source' => 'manual']);

        $this->getJson(route('offline.data'))->assertOk()
            ->assertJsonPath('accounts.0.name', 'Compte perso')
            ->assertJsonPath('accounts.0.balance', 95500)
            ->assertJsonPath('totals.all.expense', 4500)
            ->assertJsonPath('latest.0.label', 'Plein')
            ->assertJsonStructure(['generated_at', 'month', 'upcoming', 'budgets', 'goals', 'owed', 'categories', 'forecast']);

        $this->post(route('lock'));
        $this->get(route('offline.data'))->assertRedirect(route('unlock'));
    }

    public function test_movements_noted_offline_are_added_once(): void
    {
        $fuel = MoneyCategory::query()->where('name', 'Carburant, péage, parking')->value('id');
        $salary = MoneyCategory::query()->where('type', 'income')->value('id');
        $entries = [
            ['uid' => str_repeat('a', 32), 'type' => 'expense', 'amount' => 6250, 'account_id' => $this->perso->id, 'category_id' => $fuel, 'label' => 'Plein', 'occurred_on' => '2026-10-13', 'notes' => ''],
            // Catégorie de revenu sur une dépense : ignorée.
            ['uid' => str_repeat('b', 32), 'type' => 'expense', 'amount' => 1200, 'account_id' => $this->perso->id, 'category_id' => $salary, 'label' => '', 'occurred_on' => '2026-10-13'],
            // Montant invalide : refusé.
            ['uid' => str_repeat('c', 32), 'type' => 'expense', 'amount' => -5, 'account_id' => $this->perso->id, 'occurred_on' => '2026-10-13'],
        ];

        $this->postJson(route('offline.sync'), ['entries' => $entries])->assertOk()
            ->assertJson(['created' => 2, 'skipped' => 0, 'refused' => [str_repeat('c', 32)]]);
        $this->assertSame(-6250, MoneyTransaction::query()->where('label', 'Plein')->value('amount'));
        $this->assertSame($fuel, MoneyTransaction::query()->where('label', 'Plein')->value('category_id'));
        $this->assertNull(MoneyTransaction::query()->where('amount', -1200)->value('category_id'));
        $this->assertSame('Dépense', MoneyTransaction::query()->where('amount', -1200)->value('label'));

        // Renvoyé (réseau coupé pendant la réponse) : rien en double.
        $this->postJson(route('offline.sync'), ['entries' => array_slice($entries, 0, 2)])->assertOk()->assertJson(['created' => 0, 'skipped' => 2]);
        $this->assertSame(2, MoneyTransaction::query()->where('source_ref', 'like', 'offline:%')->count());
    }

    public function test_settings_show_the_offline_card(): void
    {
        $this->get(route('settings'))->assertOk()->assertSee('Mode hors ligne')->assertSee('data-offline-settings', false);
    }
}
