<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyGoal;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calculs de l'app Argent : gagné, dépensé, soldes, catégories, budgets,
 * objectifs et prévisions. « Gagné » = entrées, « dépensé » = sorties ; les
 * virements entre vos comptes ne comptent ni dans l'un ni dans l'autre.
 */
class MoneyStatsService
{
    public const SCOPES = ['all' => 'Tout', 'perso' => 'Perso', 'pro' => 'Pro'];

    public function __construct(private readonly Settings $settings) {}

    /** @return array{income: int, expense: int, net: int} */
    public function totals(string $scope, Carbon $from, Carbon $to): array
    {
        $row = MoneyTransaction::query()->real()->inScope($scope)->betweenDates($from, $to)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as income')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) as expense')
            ->first();
        $income = (int) $row->income;
        $expense = (int) $row->expense;

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    /** Variation en % par rapport à une valeur précédente (null si rien à comparer). */
    public static function change(int $current, int $previous): ?int
    {
        return $previous === 0 ? null : (int) round(($current - $previous) * 100 / abs($previous));
    }

    /**
     * Même période juste avant (du 1er au 8 du mois dernier pour « ce mois-ci » au 8, etc.).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousPeriod(string $period, Carbon $from, Carbon $to): array
    {
        return match ($period) {
            'semaine' => [$from->copy()->subWeek(), $to->copy()->subWeek()],
            'mois' => [$from->copy()->subMonthNoOverflow(), $to->copy()->subMonthNoOverflow()],
            'annee' => [$from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow()],
            default => [$from->copy()->subDays((int) $from->diffInDays($to) + 1), $from->copy()->subDay()],
        };
    }

    /** @return Collection<int, MoneyAccount> comptes actifs avec leur solde (attribut « current_balance »). */
    public function accounts(string $scope = 'all'): Collection
    {
        return MoneyAccount::query()->active()->ordered()
            ->when($scope !== 'all', fn ($q) => $q->where('scope', $scope))
            ->get()
            ->each(fn (MoneyAccount $account) => $account->setAttribute('current_balance', $account->balance()));
    }

    /**
     * Entrées et sorties des derniers mois (le mois en cours compris).
     *
     * @return list<array{month: Carbon, income: int, expense: int, net: int}>
     */
    public function monthly(string $scope, int $months = 12, ?Carbon $until = null): array
    {
        $until ??= today();
        $start = $until->copy()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $rows = MoneyTransaction::query()->real()->inScope($scope)->betweenDates($start, $until->copy()->endOfMonth())
            ->get(['occurred_on', 'amount'])
            ->groupBy(fn (MoneyTransaction $t) => $t->occurred_on->format('Y-m'));

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            $items = $rows->get($month->format('Y-m'), collect());
            $income = (int) $items->where('amount', '>', 0)->sum('amount');
            $expense = (int) -$items->where('amount', '<', 0)->sum('amount');
            $series[] = ['month' => $month, 'income' => $income, 'expense' => $expense, 'net' => $income - $expense];
        }

        return $series;
    }

    /**
     * Total par catégorie (dépenses ou revenus), du plus gros au plus petit.
     *
     * @return Collection<int, array{id: ?int, name: string, color: string, amount: int}>
     */
    public function byCategory(string $scope, Carbon $from, Carbon $to, string $type = 'expense'): Collection
    {
        $sums = MoneyTransaction::query()->real()->inScope($scope)->betweenDates($from, $to)
            ->where('amount', $type === 'expense' ? '<' : '>', 0)
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id');
        $categories = MoneyCategory::query()->whereIn('id', $sums->keys()->filter())->get()->keyBy('id');

        return $sums->map(function ($total, $id) use ($categories) {
            $category = $categories->get($id);

            return [
                'id' => $category?->id,
                'name' => $category?->name ?? 'Sans catégorie',
                'color' => $category?->color ?? '#B0BEC5',
                'amount' => abs((int) $total),
            ];
        })->sortByDesc('amount')->values();
    }

    /**
     * Budgets du mois : catégories avec un budget, ce qui est déjà dépensé.
     *
     * @return Collection<int, array{category: MoneyCategory, spent: int, budget: int, percent: int}>
     */
    public function budgets(?Carbon $month = null): Collection
    {
        $month ??= today();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $categories = MoneyCategory::query()->active()->where('type', 'expense')->whereNotNull('monthly_budget')->where('monthly_budget', '>', 0)->ordered()->get();
        $spent = MoneyTransaction::query()->real()->betweenDates($from, $to)->whereIn('category_id', $categories->pluck('id'))
            ->groupBy('category_id')->selectRaw('category_id, SUM(amount) as total')->pluck('total', 'category_id');

        return $categories->map(function (MoneyCategory $category) use ($spent) {
            $amount = max(0, (int) -($spent[$category->id] ?? 0));

            return [
                'category' => $category,
                'spent' => $amount,
                'budget' => $category->monthly_budget,
                'percent' => (int) round($amount * 100 / max(1, $category->monthly_budget)),
            ];
        });
    }

    /**
     * Avancement d'un objectif.
     *
     * @return array{current: int, target: int, percent: int, status: string, hint: ?string, period: ?string}
     */
    public function goal(MoneyGoal $goal): array
    {
        $target = max(1, $goal->target);
        $hint = null;
        $periodLabel = null;

        if ($goal->isSaving()) {
            $current = $goal->account ? $goal->account->balance() : $goal->saved;
            $left = $goal->target - $current;
            if ($left > 0 && $goal->deadline && $goal->deadline->isFuture()) {
                $months = max(1, (int) ceil(today()->floatDiffInMonths($goal->deadline)));
                $hint = Money::format((int) ceil($left / $months)).' par mois pour y arriver le '.$goal->deadline->format('d/m/Y');
            } elseif ($left > 0) {
                $hint = 'Il manque '.Money::format($left);
            }
        } else {
            [$from, $to] = $goal->period === 'annee'
                ? [today()->startOfYear(), today()]
                : [today()->startOfMonth(), today()];
            $periodLabel = $goal->period === 'annee' ? 'cette année' : 'ce mois-ci';
            $totals = $this->totals($goal->scope, $from, $to);
            $current = match ($goal->kind) {
                'encaisse' => $totals['income'],
                'depenses' => $totals['expense'],
                default => $totals['net'],
            };
            if ($goal->kind === 'depenses') {
                $left = $goal->target - $current;
                $hint = $left >= 0
                    ? 'Encore '.Money::format($left).' possibles '.$periodLabel
                    : 'Dépassé de '.Money::format(-$left).' '.$periodLabel;
            } elseif ($current < $goal->target) {
                $hint = 'Encore '.Money::format($goal->target - $current).' '.$periodLabel;
            }
        }

        $percent = (int) max(0, round($current * 100 / $target));
        $status = $goal->kind === 'depenses'
            ? ($percent > 100 ? 'danger' : ($percent >= 85 ? 'warning' : 'success'))
            : ($percent >= 100 ? 'success' : 'info');

        return ['current' => $current, 'target' => $goal->target, 'percent' => $percent, 'status' => $status, 'hint' => $hint, 'period' => $periodLabel];
    }

    /**
     * Dépenses et revenus fixes prévus d'ici la date (plusieurs fois si hebdomadaires).
     *
     * @return Collection<int, array{recurring: MoneyRecurring, date: Carbon}>
     */
    public function upcoming(Carbon $until): Collection
    {
        $items = collect();
        foreach (MoneyRecurring::query()->where('active', true)->whereDate('next_on', '<=', $until)->with(['account', 'category'])->get() as $recurring) {
            $date = $recurring->next_on->copy();
            for ($i = 0; $i < 60 && $date->lte($until); $i++) {
                $items->push(['recurring' => $recurring, 'date' => $date->copy()]);
                $date = $recurring->nextAfter($date);
            }
        }

        return $items->sortBy(fn ($item) => $item['date']->timestamp)->values();
    }

    /** Solde estimé à la fin du mois : soldes d'aujourd'hui + dépenses et revenus fixes à venir. */
    public function endOfMonthForecast(string $scope): int
    {
        $balance = (int) $this->accounts($scope)->sum('current_balance');
        $upcoming = $this->upcoming(today()->endOfMonth())
            ->filter(fn ($item) => $item['date']->isAfter(today()))
            ->filter(fn ($item) => $scope === 'all' || $item['recurring']->account?->scope === $scope);

        return $balance + (int) $upcoming->sum(fn ($item) => $item['recurring']->amount);
    }

    /**
     * Chiffres de l'app de devis : encaissé et frais de la période (copiés ici chaque
     * semaine), et ce qui restait à encaisser à la dernière mise à jour. null si jamais relié.
     *
     * @return array{to_collect: int, collected: int, spent: int, gain: int, pending_quotes: int, pending_amount: int, overdue: int}|null
     */
    public function quotes(Carbon $from, Carbon $to): ?array
    {
        $summary = $this->settings->get('devis.summary');
        if (! is_array($summary)) {
            return null;
        }
        $lines = MoneyTransaction::query()->where('source', 'devis')->betweenDates($from, $to);
        $collected = (int) (clone $lines)->where('source_ref', 'like', 'payment:%')->sum('amount');
        $spent = (int) -(clone $lines)->where('source_ref', 'like', 'expense:%')->sum('amount');

        return [
            'to_collect' => (int) ($summary['to_collect'] ?? 0),
            'collected' => $collected,
            'spent' => $spent,
            'gain' => $collected - $spent,
            'pending_quotes' => (int) ($summary['pending_quotes'] ?? 0),
            'pending_amount' => (int) ($summary['pending_amount'] ?? 0),
            'overdue' => (int) ($summary['overdue_invoices'] ?? 0),
        ];
    }
}
