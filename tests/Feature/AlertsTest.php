<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $push;

    private MoneyAccount $perso;

    private MoneyCategory $courses;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $this->push);
        $this->actingAs(User::factory()->create(['password' => 'MotDePasse-2026!']));
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '1 500'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('scope', 'perso')->sole();
        $this->courses = MoneyCategory::query()->where('name', 'Courses')->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function spend(string $amount, ?int $category = null, string $date = '2026-10-14'): void
    {
        $this->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => $amount, 'account_id' => $this->perso->id, 'category_id' => $category, 'occurred_on' => $date,
        ])->assertSessionHasNoErrors();
    }

    public function test_budget_alerts_at_80_and_100_percent_once_per_month(): void
    {
        $this->put(route('categories.update', $this->courses), ['monthly_budget' => '300'])->assertSessionHasNoErrors();

        $this->spend('200', $this->courses->id);
        $this->push->shouldNotHaveReceived('send');

        $this->spend('50', $this->courses->id);
        $this->push->shouldHaveReceived('send')->with('Budget presque atteint', '« Courses » a atteint 83 % de son budget du mois.', route('categories.index'), Mockery::any())->once();

        $this->spend('100', $this->courses->id);
        $this->spend('20', $this->courses->id);
        $this->push->shouldHaveReceived('send')->with('Budget dépassé', '« Courses » a dépassé son budget du mois.', Mockery::any(), Mockery::any())->once();
        $this->get(route('dashboard'))->assertOk()->assertSee('Budget dépassé')->assertSee('370,00 € dépensés sur 300,00 €', false);

        // Nouveau mois : le compteur repart, la notification « 80 % » peut revenir.
        Carbon::setTestNow('2026-11-03 10:00');
        $this->post(route('unlock.store'), ['code' => '482913']);
        $this->spend('260', $this->courses->id, '2026-11-03');
        $this->push->shouldHaveReceived('send')->with('Budget presque atteint', Mockery::any(), Mockery::any(), Mockery::any())->twice();
    }

    public function test_low_balance_alert_once_until_back_above(): void
    {
        $this->put(route('accounts.update', $this->perso), [
            'name' => 'Compte perso', 'kind' => 'courant', 'scope' => 'perso', 'opening_balance' => '1 500', 'opening_on' => '2026-10-14', 'alert_below' => '1 000',
        ])->assertSessionHasNoErrors();
        $this->assertSame(100000, $this->perso->fresh()->alert_below);

        $this->spend('600');
        $this->spend('50');
        $this->push->shouldHaveReceived('send')->with('Solde bas', '« Compte perso » est passé sous votre seuil d\'alerte.', route('accounts.show', $this->perso), Mockery::any())->once();
        $this->get(route('dashboard'))->assertSee('Solde bas');

        // Remonté au-dessus du seuil, puis de nouveau en dessous : nouvelle alerte.
        $this->post(route('transactions.store'), ['type' => 'income', 'amount' => '500', 'account_id' => $this->perso->id, 'occurred_on' => '2026-10-14']);
        $this->get(route('dashboard'))->assertDontSee('Solde bas');
        // 1 350 € → 950 € : de nouveau sous 1 000 €.
        $this->spend('400');
        $this->push->shouldHaveReceived('send')->with('Solde bas', Mockery::any(), Mockery::any(), Mockery::any())->twice();
    }

    public function test_amounts_only_when_asked_and_alerts_can_be_turned_off(): void
    {
        $this->put(route('settings.update'), ['lock_minutes' => 15, 'push_amounts' => 1, 'alerts_enabled' => 1, 'alerts_budget_warning' => 0])->assertSessionHasNoErrors();
        $this->put(route('categories.update', $this->courses), ['monthly_budget' => '100'])->assertSessionHasNoErrors();
        $this->spend('90', $this->courses->id);
        $this->push->shouldNotHaveReceived('send');
        $this->spend('20', $this->courses->id);
        $this->push->shouldHaveReceived('send')->with('Budget dépassé', '« Courses » a dépassé son budget du mois. 110,00 € dépensés sur 100,00 €.', Mockery::any(), Mockery::any())->once();

        $this->put(route('settings.update'), ['lock_minutes' => 15])->assertSessionHasNoErrors();
        $this->put(route('categories.update', $this->courses), ['monthly_budget' => '50'])->assertSessionHasNoErrors();
        $this->artisan('app:argent-alertes')->assertSuccessful()->expectsOutput('0 alerte(s) envoyée(s).');
    }
}
