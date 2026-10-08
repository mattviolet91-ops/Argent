<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyCredit;
use App\Models\MoneyLoanEntry;
use App\Models\MoneyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patrimoine net : comptes (courants, épargne, placements, moins les dettes) +
 * ce qu'on vous doit − ce que vous devez − capital restant des crédits, à la fin
 * de chaque mois.
 */
class MoneyWealthService
{
    /**
     * @return list<array{date: Carbon, accounts: int, groups: array<string, int>, loans: int, credits: int, net: int}>
     */
    public function history(int $months = 12): array
    {
        $accounts = MoneyAccount::query()->get(['id', 'kind', 'opening_balance', 'opening_on']);
        // Mouvements par compte et par mois (depuis la date de départ de chaque compte).
        $sums = MoneyTransaction::query()->toBase()
            ->join('money_accounts', 'money_accounts.id', '=', 'money_transactions.account_id')
            ->whereColumn('money_transactions.occurred_on', '>=', 'money_accounts.opening_on')
            ->whereDate('money_transactions.occurred_on', '<=', today())
            ->groupBy('money_transactions.account_id', 'ym')
            ->selectRaw('money_transactions.account_id as account_id, SUBSTR(money_transactions.occurred_on, 1, 7) as ym, SUM(money_transactions.amount) as total')
            ->get()->groupBy('account_id');
        $loans = MoneyLoanEntry::query()->toBase()->whereDate('occurred_on', '<=', today())
            ->groupBy('ym')->selectRaw('SUBSTR(occurred_on, 1, 7) as ym, SUM(amount) as total')->pluck('total', 'ym');
        $credits = MoneyCredit::query()->whereNull('archived_at')->get();

        $points = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $i === 0 ? today() : today()->startOfMonth()->subMonthsNoOverflow($i)->endOfMonth();
            $ym = $date->format('Y-m');
            $groups = array_fill_keys(array_keys(MoneyAccount::GROUPS), 0);
            foreach ($accounts as $account) {
                if ($account->opening_on->gt($date)) {
                    continue;
                }
                $moved = (int) collect($sums->get($account->id, []))->filter(fn ($row) => $row->ym <= $ym)->sum('total');
                $groups[$account->group()] += (int) $account->opening_balance + $moved;
            }
            $loanTotal = (int) $loans->filter(fn ($total, $month) => $month <= $ym)->sum();
            // Un crédit compte à partir du mois qui précède sa 1re mensualité (l'argent reçu).
            $creditTotal = (int) $credits->sum(fn (MoneyCredit $c) => $date->lt($c->first_due_on->copy()->subMonthNoOverflow()) ? 0 : $c->remaining($date));
            $accountTotal = (int) array_sum($groups);
            $points[] = [
                'date' => $date, 'accounts' => $accountTotal, 'groups' => $groups,
                'loans' => $loanTotal, 'credits' => $creditTotal, 'net' => $accountTotal + $loanTotal - $creditTotal,
            ];
        }

        return $points;
    }

    /**
     * Le détail d'aujourd'hui : chaque ligne du patrimoine.
     *
     * @return Collection<int, array{label: string, amount: int, url: ?string}>
     */
    public function breakdown(array $today, int $owed, int $owing): Collection
    {
        $rows = collect();
        foreach (MoneyAccount::GROUPS as $key => $label) {
            if ($today['groups'][$key] !== 0) {
                $rows->push(['label' => $label, 'amount' => $today['groups'][$key], 'url' => route('accounts.index')]);
            }
        }
        if ($owed) {
            $rows->push(['label' => 'On vous doit', 'amount' => $owed, 'url' => route('loans.index')]);
        }
        if ($owing) {
            $rows->push(['label' => 'Vous devez', 'amount' => -$owing, 'url' => route('loans.index')]);
        }
        if ($today['credits']) {
            $rows->push(['label' => 'Crédits (capital restant)', 'amount' => -$today['credits'], 'url' => route('credits.index')]);
        }

        return $rows;
    }
}
