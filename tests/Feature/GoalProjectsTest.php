<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\MoneyGoal;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Models\User;
use App\Services\PushService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GoalProjectsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $push;

    private MoneyAccount $perso;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->push = Mockery::spy(PushService::class);
        $this->app->instance(PushService::class, $this->push);
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '20 000'])->assertSessionHasNoErrors();
        $this->perso = MoneyAccount::query()->where('name', 'Compte perso')->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function newCarGoal(): MoneyGoal
    {
        $this->post(route('goals.store'), [
            'name' => 'Nouvelle voiture', 'kind' => 'epargne', 'icon' => 'voiture', 'target' => '15 000',
            'deadline' => '2027-10-14', 'account_id' => 'new', 'saved' => '1 000',
        ])->assertSessionHasNoErrors();

        return MoneyGoal::query()->where('name', 'Nouvelle voiture')->sole();
    }

    public function test_project_goal_gets_its_own_account_and_shows_the_pace(): void
    {
        $goal = $this->newCarGoal();
        $account = $goal->account;
        $this->assertSame('objectif', $account->kind);
        $this->assertSame('Objectif · Nouvelle voiture', $account->name);
        $this->assertSame(100000, $account->balance());

        // 14 000 € à réunir en 12 mois.
        $this->get(route('goals.show', $goal))->assertOk()
            ->assertSee('Nouvelle voiture')->assertSee('7 %')
            ->assertSee(Money::format((int) ceil(1400000 / 12)))
            ->assertSee('échéance le 14/10/2027');
        $this->get(route('goals.index'))->assertOk()->assertSee('compte séparé');
        $this->get(route('accounts.index'))->assertSee('Objectif · Nouvelle voiture');
    }

    public function test_deposits_withdrawals_and_planned_deposits(): void
    {
        $goal = $this->newCarGoal();

        $this->post(route('goals.deposit', $goal), ['mode' => 'deposit', 'move_amount' => '500', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-10-14'])
            ->assertRedirect(route('goals.show', $goal))->assertSessionHas('status', 'Dépôt enregistré.');
        $this->post(route('goals.deposit', $goal), ['mode' => 'deposit', 'move_amount' => '300', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-11-05', 'notes' => 'Prime'])
            ->assertSessionHas('status', 'Dépôt prévu le 05/11/2026.');

        $this->assertSame(150000, $goal->account->balance());
        $this->assertSame(2000000 - 50000, $this->perso->balance());
        $this->get(route('goals.show', $goal))->assertSee('prévu')->assertSee('Prime')->assertSee('Dépôts déjà prévus');
        // Un dépôt est un virement : ni gagné ni dépensé.
        $this->assertSame(0, MoneyTransaction::query()->where('kind', '!=', 'transfer')->count());

        $this->post(route('goals.withdraw', $goal), ['mode' => 'withdraw', 'move_amount' => '5 000', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasErrors('move_amount');
        $this->post(route('goals.withdraw', $goal), ['mode' => 'withdraw', 'move_amount' => '200', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHas('status', 'Retrait enregistré.');
        $this->assertSame(130000, $goal->account->balance());

        // Le jour venu, le dépôt prévu compte.
        Carbon::setTestNow('2026-11-05 09:00');
        $this->assertSame(160000, $goal->account->balance());
    }

    public function test_automatic_deposits_and_projection(): void
    {
        $goal = $this->newCarGoal();
        $this->post(route('goals.automatic', $goal), ['auto_amount' => '1 000', 'from_account_id' => $this->perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-10-14'])
            ->assertRedirect(route('goals.show', $goal));

        $recurring = MoneyRecurring::query()->sole();
        $this->assertSame($goal->account_id, $recurring->to_account_id);
        $this->assertSame('2026-11-14', $recurring->next_on->toDateString());
        // Premier versement fait tout de suite : 1 000 + 1 000.
        $this->assertSame(200000, $goal->account->balance());
        $this->assertSame(2, MoneyTransaction::query()->where('recurring_id', $recurring->id)->where('kind', 'transfer')->count());

        // Reste 13 000 € à 1 000 €/mois : atteint dans 13 mois, après l'échéance.
        $this->get(route('goals.show', $goal))->assertOk()
            ->assertSee('Versements automatiques (par mois)')
            ->assertSee('novembre 2027')->assertSee('en retard');
        $this->get(route('recurrings.index'))->assertSee('Versement · Nouvelle voiture')->assertSee('mis de côté');

        Carbon::setTestNow('2026-11-14 07:00');
        $this->artisan('app:argent-jour')->assertSuccessful();
        $this->assertSame(300000, $goal->account->balance());
    }

    public function test_reaching_the_target_is_celebrated_once(): void
    {
        $goal = $this->newCarGoal();
        $this->post(route('goals.deposit', $goal), ['mode' => 'deposit', 'move_amount' => '14 000', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHas('status', 'Objectif « Nouvelle voiture » atteint, bravo !');
        $this->assertNotNull($goal->fresh()->achieved_at);
        $this->post(route('goals.deposit', $goal), ['mode' => 'deposit', 'move_amount' => '10', 'other_account_id' => $this->perso->id, 'occurred_on' => '2026-10-14']);
        $this->push->shouldHaveReceived('send')->with('Objectif atteint 🎉', '« Nouvelle voiture » : le montant est réuni. Bravo !', route('goals.show', $goal), Mockery::any())->once();
        $this->get(route('goals.show', $goal))->assertSee('Objectif atteint');
    }

    public function test_hand_written_goal_can_move_to_its_own_account(): void
    {
        $this->post(route('goals.store'), ['name' => 'Vacances', 'kind' => 'epargne', 'target' => '2 000', 'account_id' => '', 'saved' => '300'])->assertSessionHasNoErrors();
        $goal = MoneyGoal::query()->sole();
        $this->assertNull($goal->account_id);
        $this->post(route('goals.contribute', $goal), ['contribution' => '200'])->assertRedirect(route('goals.show', $goal));

        $this->post(route('goals.account', $goal))->assertRedirect(route('goals.show', $goal));
        $goal->refresh();
        $this->assertSame(50000, $goal->account->balance());
        $this->assertSame(0, $goal->saved);
        $this->get(route('goals.show', $goal))->assertSee('Déposer');
    }

    public function test_fixed_transfer_from_the_fixes_page(): void
    {
        $livret = MoneyAccount::query()->create(['name' => 'Livret A', 'kind' => 'epargne', 'scope' => 'perso', 'opening_on' => '2026-10-01']);
        $this->post(route('recurrings.store'), [
            'label' => 'Épargne du mois', 'type' => 'transfer', 'amount' => '150', 'account_id' => $this->perso->id, 'to_account_id' => $livret->id,
            'frequency' => 'mensuel', 'next_on' => '2026-10-20',
        ])->assertSessionHasNoErrors();
        $this->post(route('recurrings.store'), ['label' => 'X', 'type' => 'transfer', 'amount' => '10', 'account_id' => $this->perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-10-20'])
            ->assertSessionHasErrors('to_account_id');

        // Virement interne : le solde total prévu fin de mois ne bouge pas.
        $this->get(route('dashboard'))->assertOk()->assertSee('Épargne du mois → Livret A')->assertViewHas('forecast', 2000000);
        Carbon::setTestNow('2026-10-20 07:00');
        $this->artisan('app:argent-jour')->assertSuccessful();
        $this->assertSame(15000, $livret->balance());
    }
}
