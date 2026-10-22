<?php

namespace App\Services;

use App\Http\Controllers\ClassifyController;
use App\Models\MoneyGoal;
use App\Models\MoneyTransaction;
use App\Models\MoneyWidgetKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Résumé lu par les widgets iPhone (Scriptable) : textes déjà mis en forme, rien d'autre.
 * Montants masqués : « ••• » à la place, seuls les pourcentages et le sens restent.
 */
class MoneyWidgetService
{
    /** Version du script donné à l'installation : le widget signale quand il y en a une plus récente. */
    public const SCRIPT_VERSION = 1;

    /** Jours de la courbe du solde. */
    public const DAYS = 30;

    public function __construct(private readonly MoneyStatsService $stats) {}

    /** @return array<string, mixed> */
    public function payload(MoneyWidgetKey $key, string $scope): array
    {
        $show = $key->show_amounts;
        $money = fn (int $cents, bool $sign = false): string => $show ? self::euros($cents, $sign) : '•••';

        $accounts = $this->stats->accounts($scope);
        $balance = (int) $accounts->sum('current_balance');
        $history = $this->history($accounts->pluck('id')->all(), $balance);
        // Moins d'un euro d'écart sur la période : stable.
        $delta = abs($balance - $history[0]) < 50 ? 0 : $balance - $history[0];
        $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        $month = $this->stats->totals($scope, today()->startOfMonth(), today());
        $forecast = $this->stats->endOfMonthForecast($scope);

        return [
            'v' => 1,
            'script' => self::SCRIPT_VERSION,
            'url' => route('dashboard'),
            'updated' => now()->toIso8601String(),
            'time' => now()->format('H:i'),
            'view' => $scope,
            'view_label' => MoneyStatsService::SCOPES[$scope],
            'hidden' => ! $show,
            'balance' => ['text' => $money($balance), 'short' => $show ? self::short($balance) : '•••', 'negative' => $balance < 0],
            'trend' => [
                'dir' => $direction,
                'text' => $show
                    ? ($delta === 0 ? 'Stable' : self::euros($delta, true)).' en '.self::DAYS.' jours'
                    : ['up' => 'En hausse', 'down' => 'En baisse', 'flat' => 'Stable'][$direction].' sur '.self::DAYS.' jours',
            ],
            'history' => self::normalize($history),
            'month' => [
                'label' => Str::ucfirst(today()->locale('fr')->isoFormat('MMMM')),
                'income' => $money($month['income'], true),
                'expense' => $money(-$month['expense'], true),
                'result' => $money($month['net'], true),
                'positive' => $month['net'] >= 0,
            ],
            'forecast' => ['text' => $money($forecast), 'negative' => $forecast < 0],
            'budget' => $scope === 'pro' ? null : $this->budget($money),
            'goal' => $this->goal($money),
            'upcoming' => $this->upcoming($scope, $money),
            'to_classify' => ClassifyController::pending()->count(),
        ];
    }

    /**
     * Solde des comptes de la vue à la fin de chaque jour, du plus ancien à aujourd'hui.
     *
     * @param  list<int>  $accountIds
     * @return list<int>
     */
    public function history(array $accountIds, int $current): array
    {
        $from = today()->subDays(self::DAYS);
        $sums = MoneyTransaction::query()
            ->join('money_accounts', 'money_accounts.id', '=', 'money_transactions.account_id')
            ->whereIn('money_transactions.account_id', $accountIds)
            ->whereColumn('money_transactions.occurred_on', '>=', 'money_accounts.opening_on')
            ->whereDate('money_transactions.occurred_on', '>', $from)
            ->whereDate('money_transactions.occurred_on', '<=', today())
            ->toBase()
            ->groupBy('day')
            ->selectRaw('SUBSTR(money_transactions.occurred_on, 1, 10) as day, SUM(money_transactions.amount) as total')
            ->pluck('total', 'day');

        $values = [self::DAYS => $current];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $next = $from->copy()->addDays($i + 1)->toDateString();
            $values[$i] = $values[$i + 1] - (int) ($sums[$next] ?? 0);
        }
        ksort($values);

        return array_values($values);
    }

    /**
     * Courbe ramenée entre 0 et 1 : la forme seulement, aucun montant.
     *
     * @param  list<int>  $values
     * @return list<float>
     */
    public static function normalize(array $values): array
    {
        $min = min($values);
        $range = max($values) - $min;

        return array_map(fn (int $v) => $range === 0 ? 0.5 : round(($v - $min) / $range, 3), $values);
    }

    /** @return array{percent: int, text: string, left: string, over: bool, over_count: int}|null */
    private function budget(\Closure $money): ?array
    {
        $budgets = $this->stats->budgets();
        if ($budgets->isEmpty()) {
            return null;
        }
        $spent = (int) $budgets->sum('spent');
        $total = (int) $budgets->sum('budget');

        return [
            'percent' => (int) round($spent * 100 / max(1, $total)),
            'text' => $money($spent).' / '.$money($total),
            'left' => $spent <= $total ? 'Reste '.$money($total - $spent) : 'Dépassé de '.$money($spent - $total),
            'over' => $spent > $total,
            'over_count' => $budgets->where('percent', '>', 100)->count(),
        ];
    }

    /** Le premier objectif d'épargne en cours. */
    private function goal(\Closure $money): ?array
    {
        $goal = MoneyGoal::query()->whereNull('archived_at')->where('kind', 'epargne')->with('account')->orderBy('id')->first();
        if (! $goal) {
            return null;
        }
        $progress = $this->stats->goal($goal);

        return ['name' => $goal->name, 'percent' => min(100, $progress['percent']), 'text' => $money($progress['current']).' / '.$money($progress['target'])];
    }

    /** @return list<array{day: string, label: string, text: string, income: bool}> */
    private function upcoming(string $scope, \Closure $money): array
    {
        return $this->stats->upcoming(today()->addDays(31))
            ->filter(fn ($item) => $item['date']->isAfter(today()))
            ->filter(fn ($item) => $scope === 'all' || $item['recurring']->account?->scope === $scope || $item['recurring']->toAccount?->scope === $scope)
            ->take(4)
            ->map(function ($item) use ($scope, $money) {
                $recurring = $item['recurring'];
                $amount = $recurring->isTransfer() ? $recurring->effectOn($scope) : $recurring->amount;

                return [
                    'day' => self::day($item['date']),
                    'label' => $recurring->label,
                    // Virement entre deux comptes de la vue : ne change pas le total.
                    'text' => $amount === 0 ? '↔ '.$money(abs($recurring->amount)) : $money($amount, true),
                    'income' => $amount > 0,
                ];
            })->values()->all();
    }

    /** « Demain », sinon « sam. 25 ». */
    private static function day(Carbon $date): string
    {
        return $date->isTomorrow() ? 'Demain' : $date->locale('fr')->isoFormat('ddd D');
    }

    /** 324512 → « 3 245 € » (à l'euro près) ; avec le signe : « +3 245 € », « −80 € ». */
    public static function euros(int $cents, bool $sign = false): string
    {
        $euros = (int) round(abs($cents) / 100);
        $prefix = $cents < 0 && $euros > 0 ? '−' : ($sign && $euros > 0 ? '+' : '');

        return $prefix.number_format($euros, 0, ',', "\u{202F}")."\u{00A0}€";
    }

    /** Pour le petit rond de l'écran verrouillé : « 3 245 € », « 32,4 k€ », « 1,2 M€ ». */
    public static function short(int $cents): string
    {
        $euros = abs($cents) / 100;
        $sign = $cents < 0 && round($euros) > 0 ? '−' : '';

        return $sign.match (true) {
            $euros < 10000 => number_format(round($euros), 0, ',', "\u{202F}")."\u{00A0}€",
            $euros < 1000000 => number_format(floor($euros / 100) / 10, 1, ',', '')."\u{00A0}k€",
            default => number_format(floor($euros / 100000) / 10, 1, ',', '')."\u{00A0}M€",
        };
    }
}
