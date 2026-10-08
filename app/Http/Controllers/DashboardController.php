<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Http\Controllers\Concerns\ResolvesPeriod;
use App\Models\MoneyGoal;
use App\Models\MoneyTransaction;
use App\Services\MoneyAlertService;
use App\Services\MoneyStatsService;
use App\Services\MoneySyncService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Tableau de bord Argent : soldes, gagné / dépensé, graphiques, budgets, objectifs, devis. */
class DashboardController extends Controller
{
    use ReadsMoneyInput, ResolvesPeriod;

    public const PERIODS = [
        'semaine' => 'Semaine',
        'mois' => 'Mois',
        'annee' => 'Année',
    ];

    public function __invoke(Request $request, MoneyStatsService $stats, MoneySyncService $sync, Settings $settings): View
    {
        $scope = $this->scope($request);
        [$period, $from, $to] = $this->period($request, 'mois');
        [$prevFrom, $prevTo] = MoneyStatsService::previousPeriod($period, $from, $to);

        $totals = $stats->totals($scope, $from, $to);
        $previous = $stats->totals($scope, $prevFrom, $prevTo);
        $accounts = $stats->accounts($scope);
        $quotes = $scope !== 'perso' ? $stats->quotes($from, $to) : null;
        $lastSync = $settings->get('argent.last_sync_at');

        return view('dashboard', [
            'scope' => $scope,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'previous' => $previous,
            'balance' => (int) $accounts->sum('current_balance'),
            'accounts' => $accounts,
            'monthly' => $stats->monthly($scope),
            'categories' => $stats->byCategory($scope, $from, $to),
            'budgets' => $stats->budgets(),
            'goals' => MoneyGoal::query()->whereNull('archived_at')->with('account')->orderBy('id')->get()
                ->map(fn (MoneyGoal $goal) => ['goal' => $goal] + $stats->goal($goal)),
            'upcoming' => $stats->upcoming(today()->endOfMonth())->filter(fn ($item) => $item['date']->isAfter(today()))
                ->filter(fn ($item) => $scope === 'all' || $item['recurring']->account?->scope === $scope)->take(6),
            'forecast' => $stats->endOfMonthForecast($scope),
            'quotes' => $quotes,
            'syncAccount' => $sync->account(),
            'lastSync' => $lastSync ? Carbon::parse($lastSync) : null,
            'devisUrl' => rtrim((string) $settings->get('devis.url', ''), '/'),
            'alerts' => app(MoneyAlertService::class)->current(),
            'latest' => MoneyTransaction::query()->inScope($scope)->with(['account', 'category'])
                ->whereDate('occurred_on', '<=', today())->latest('occurred_on')->latest('id')->limit(8)->get(),
            'accountOptions' => $this->accountOptions(),
            'categoryOptions' => $this->categoryOptions(),
        ]);
    }
}
