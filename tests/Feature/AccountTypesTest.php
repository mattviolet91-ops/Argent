<?php

namespace Tests\Feature;

use App\Models\MoneyAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        $this->actingAs(User::factory()->create());
        $this->post(route('setup.store'), ['code' => '482913', 'code_confirmation' => '482913', 'perso_balance' => '2 000'])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addAccount(string $name, string $kind, string $balance): MoneyAccount
    {
        $this->post(route('accounts.store'), ['name' => $name, 'kind' => $kind, 'scope' => 'perso', 'opening_balance' => $balance, 'opening_on' => '2026-10-01'])
            ->assertSessionHasNoErrors();

        return MoneyAccount::query()->where('name', $name)->sole();
    }

    public function test_savings_investments_and_debts_are_grouped_with_net_worth(): void
    {
        $this->addAccount('Livret A', 'epargne', '5 000');
        $this->addAccount('Assurance-vie Generali', 'assurance_vie', '12 000');
        $this->addAccount('Ledger', 'crypto', '800');
        // Crédit : on tape ce qu'il reste à rembourser, il est compté en négatif.
        $credit = $this->addAccount('Prêt camion', 'credit', '9 500');
        $this->assertSame(-950000, $credit->opening_balance);
        $this->assertTrue($credit->isDebt());

        // Remboursement : virement du compte courant vers le crédit.
        $perso = MoneyAccount::query()->where('name', 'Compte perso')->sole();
        $this->post(route('transactions.store'), ['type' => 'transfer', 'amount' => '500', 'account_id' => $perso->id, 'to_account_id' => $credit->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasNoErrors();
        $this->assertSame(-900000, $credit->balance());

        $this->get(route('accounts.index'))->assertOk()
            ->assertSeeInOrder(['Comptes courants', 'Compte perso', 'Épargne et placements', 'Livret A', 'Assurance-vie Generali', 'Ledger', 'Crédits et dettes', 'Prêt camion'])
            ->assertSee('Reste à rembourser')
            ->assertSee(Money::format(150000 + 500000 + 1200000 + 80000 - 900000)) // patrimoine net
            ->assertSee(Money::format(1780000)); // épargne et placements
        $this->get(route('accounts.show', $credit))->assertOk()->assertSee('Reste à rembourser')->assertSee(Money::format(900000));
    }

    public function test_every_type_can_be_chosen_and_unknown_types_are_refused(): void
    {
        foreach (array_keys(MoneyAccount::TYPES) as $i => $kind) {
            $this->addAccount('Compte '.$i, $kind, '10');
        }
        $this->get(route('accounts.index'))->assertOk()->assertSee('Titres-restaurant')->assertSee('Épargne retraite (PER)');
        $this->post(route('accounts.store'), ['name' => 'X', 'kind' => 'inconnu', 'scope' => 'perso', 'opening_on' => '2026-10-01'])->assertSessionHasErrors('kind');
    }
}
