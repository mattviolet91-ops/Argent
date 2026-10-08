<?php

namespace App\Services;

use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tendances : « ce mois-ci, vous dépensez 30 % de plus en Restaurants que d'habitude ».
 * Le mois en cours (du 1er à aujourd'hui) est comparé à la moyenne des 3 mois
 * d'avant sur les mêmes jours (du 1er au même jour), pour comparer ce qui est
 * comparable même en début de mois.
 */
class MoneyTrendService
{
    /** Mois d'avant qui font « l'habitude ». */
    public const MONTHS = 3;

    /** Écart minimum pour en parler : en %, et en centimes. */
    public const MIN_PERCENT = 25;

    public const MIN_GAP = 2000;

    /**
     * Toutes les catégories de dépense, du plus gros poste au plus petit.
     *
     * @return array{total: array{current: int, usual: int, change: ?int}, categories: Collection<int, array<string, mixed>>, day: int}
     */
    public function compare(string $scope = 'all', ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $day = $today->day;

        $current = $this->sums($scope, $today->copy()->startOfMonth(), $today);
        $past = [];
        $full = [];
        for ($i = 1; $i <= self::MONTHS; $i++) {
            $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $past[] = $this->sums($scope, $start, $start->copy()->addDays(min($day, $start->daysInMonth) - 1));
            $full[] = $this->sums($scope, $start, $start->copy()->endOfMonth());
        }

        $ids = collect([$current, ...$past])->flatMap(fn ($sums) => array_keys($sums))->unique();
        $categories = MoneyCategory::query()->whereIn('id', $ids->filter(fn ($id) => $id !== ''))->get()->keyBy('id');

        $rows = $ids->map(function ($id) use ($current, $past, $full, $categories) {
            $category = $categories->get($id);
            $now = $current[$id] ?? 0;
            $usual = (int) round(array_sum(array_map(fn ($sums) => $sums[$id] ?? 0, $past)) / self::MONTHS);
            $months = count(array_filter($past, fn ($sums) => ($sums[$id] ?? 0) > 0));

            return [
                'id' => $category?->id,
                'name' => $category?->name ?? 'Sans catégorie',
                'color' => $category?->color ?? '#B0BEC5',
                'current' => $now,
                'usual' => $usual,
                'usual_month' => (int) round(array_sum(array_map(fn ($sums) => $sums[$id] ?? 0, $full)) / self::MONTHS),
                'months' => $months,
                'change' => MoneyStatsService::change($now, $usual),
            ];
        })->sortByDesc(fn ($row) => max($row['current'], $row['usual']))->values();

        $totalNow = (int) array_sum($current);
        $totalUsual = (int) round(array_sum(array_map('array_sum', $past)) / self::MONTHS);

        return [
            'total' => ['current' => $totalNow, 'usual' => $totalUsual, 'change' => MoneyStatsService::change($totalNow, $totalUsual)],
            'categories' => $rows,
            'day' => $day,
        ];
    }

    /**
     * Les écarts qui méritent d'être signalés : les hausses d'abord (les plus grosses en euros).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function notable(string $scope = 'all', ?Carbon $today = null, int $limit = 4): Collection
    {
        return $this->compare($scope, $today)['categories']
            ->filter(fn ($row) => $row['id'] !== null && self::isNotable($row))
            ->map(fn ($row) => $row + ['sentence' => self::sentence($row), 'up' => $row['current'] > $row['usual']])
            ->sortBy([['up', 'desc'], fn ($a, $b) => abs($b['current'] - $b['usual']) <=> abs($a['current'] - $a['usual'])])
            ->take($limit)
            ->values();
    }

    /** Un poste habituel (au moins 2 des 3 mois) qui s'écarte nettement de l'habitude. */
    public static function isNotable(array $row): bool
    {
        return $row['months'] >= 2 && $row['usual'] >= self::MIN_GAP && $row['change'] !== null
            && abs($row['change']) >= self::MIN_PERCENT && abs($row['current'] - $row['usual']) >= self::MIN_GAP;
    }

    /** « Ce mois-ci, vous dépensez 30 % de plus en restaurants que d'habitude. » */
    public static function sentence(array $row): string
    {
        $change = (int) $row['change'];
        $name = self::lower($row['name']);
        if ($change <= -95) {
            return 'Ce mois-ci, rien dépensé pour l\'instant en '.$name.'.';
        }

        return 'Ce mois-ci, vous dépensez '.abs($change).' % de '.($change > 0 ? 'plus' : 'moins').' en '.$name.' que d\'habitude.';
    }

    /** « Restaurants, sorties » → « restaurants, sorties » (mais « URSSAF » reste en capitales). */
    private static function lower(string $name): string
    {
        $second = mb_substr($name, 1, 1);

        return $second !== '' && mb_strtolower($second) === $second ? mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1) : $name;
    }

    /**
     * Dépenses par catégorie sur la période (montants positifs, '' = sans catégorie).
     *
     * @return array<int|string, int>
     */
    private function sums(string $scope, Carbon $from, Carbon $to): array
    {
        return MoneyTransaction::query()->real()->inScope($scope)->betweenDates($from, $to)->where('amount', '<', 0)
            ->groupBy('category_id')->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id')
            ->mapWithKeys(fn ($total, $id) => [$id === null || $id === '' ? '' : (int) $id => (int) -$total])
            ->all();
    }
}
