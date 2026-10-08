<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bilan d'un mois en PDF (à garder, ou pour le comptable) : gagné, dépensé,
 * comparaisons, catégories, budgets, comptes en fin de mois, plus grosses
 * dépenses, chantiers, notes de frais, devis, et si demandé tous les mouvements.
 */
class MoneyMonthReportService
{
    public function __construct(private readonly MoneyStatsService $stats) {}

    /** @return array<string, mixed> */
    public function data(Carbon $month, string $scope, bool $detail): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $until = $to->copy()->min(today());
        $totals = $this->stats->totals($scope, $from, $to);
        $previous = $this->stats->totals($scope, $from->copy()->subMonthNoOverflow(), $from->copy()->subMonthNoOverflow()->endOfMonth());
        $lastYear = $this->stats->totals($scope, $from->copy()->subYearNoOverflow(), $from->copy()->subYearNoOverflow()->endOfMonth());

        $real = MoneyTransaction::query()->real()->inScope($scope)->betweenDates($from, $to);

        return [
            'month' => $from,
            'scope' => $scope,
            'scopeLabel' => ['all' => 'Perso + pro', 'perso' => 'Perso', 'pro' => 'Pro'][$scope],
            'totals' => $totals,
            'previous' => $previous,
            'lastYear' => $lastYear,
            'split' => $scope === 'all' ? ['Perso' => $this->stats->totals('perso', $from, $to), 'Pro' => $this->stats->totals('pro', $from, $to)] : null,
            'expenses' => $this->stats->byCategory($scope, $from, $to),
            'incomes' => $this->stats->byCategory($scope, $from, $to, 'income'),
            'budgets' => $scope === 'pro' ? collect() : $this->stats->budgets($from),
            'accounts' => $this->balances($scope, $until),
            'balanceDate' => $until,
            'top' => (clone $real)->where('amount', '<', 0)->with(['category', 'account'])->orderBy('amount')->limit(10)->get(),
            'tags' => DB::table('money_tag_transaction as tt')
                ->join('money_transactions as t', 't.id', '=', 'tt.transaction_id')->join('money_tags as g', 'g.id', '=', 'tt.tag_id')
                ->where('t.kind', '!=', 'transfer')->whereBetween('t.occurred_on', [$from->toDateString(), $to->toDateString()])
                ->whereIn('t.id', (clone $real)->select('money_transactions.id'))
                ->groupBy('g.id', 'g.name')
                ->selectRaw('g.name, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) as income, COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) as expense')
                ->orderBy('g.name')->get(),
            'claimsPending' => (int) -MoneyTransaction::query()->where('claim', 'a_rembourser')->whereDate('occurred_on', '<=', $to)->sum('amount'),
            'claimsSettled' => (int) -MoneyTransaction::query()->where('claim', 'rembourse')->whereBetween('claim_settled_on', [$from->toDateString(), $to->toDateString()])->sum('amount'),
            'quotes' => $scope !== 'perso' ? $this->stats->quotes($from, $to) : null,
            'transactions' => $detail
                ? MoneyTransaction::query()->inScope($scope)->betweenDates($from, $to)->with(['category', 'account'])->orderBy('occurred_on')->orderBy('id')->get()
                : null,
        ];
    }

    /** Le PDF (contenu binaire). */
    public function pdf(Carbon $month, string $scope, bool $detail): string
    {
        $html = view('pdf.month', $this->data($month, $scope, $detail))->render();
        $cache = storage_path('app/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(500, 815, 'Page {PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.45, 0.45, 0.45]);

        return (string) $dompdf->output();
    }

    /**
     * Solde de chaque compte à la date.
     *
     * @return list<array{name: string, group: string, balance: int}>
     */
    private function balances(string $scope, Carbon $at): array
    {
        $accounts = MoneyAccount::query()->ordered()->whereDate('opening_on', '<=', $at)
            ->when($scope !== 'all', fn ($q) => $q->where('scope', $scope))->get();
        $sums = MoneyTransaction::query()->join('money_accounts', 'money_accounts.id', '=', 'money_transactions.account_id')
            ->whereColumn('money_transactions.occurred_on', '>=', 'money_accounts.opening_on')
            ->whereDate('money_transactions.occurred_on', '<=', $at)
            ->groupBy('money_transactions.account_id')
            ->selectRaw('money_transactions.account_id as id, SUM(money_transactions.amount) as total')->pluck('total', 'id');

        return $accounts->filter(fn (MoneyAccount $a) => ! $a->archived_at || (int) ($sums[$a->id] ?? 0) + $a->opening_balance !== 0)
            ->map(fn (MoneyAccount $a) => [
                'name' => $a->name,
                'group' => MoneyAccount::GROUPS[$a->group()] ?? '',
                'balance' => (int) $a->opening_balance + (int) ($sums[$a->id] ?? 0),
            ])->values()->all();
    }
}
