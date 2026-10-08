<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyWeeklyReport;
use App\Services\MoneyStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Bilans : chaque semaine (figés), chaque mois de l'année et l'année entière. */
class ReportController extends Controller
{
    use ReadsMoneyInput;

    public function index(Request $request, MoneyStatsService $stats): View
    {
        $scope = $this->scope($request);
        $year = (int) $request->query('annee', today()->year);
        $year = max(2000, min(today()->year, $year));
        $until = $year === today()->year ? today() : today()->setDate($year, 12, 31);
        $months = array_values(array_filter($stats->monthly($scope, (int) $until->month, $until), fn ($m) => $m['month']->year === $year));
        $from = today()->setDate($year, 1, 1);

        return view('reports.index', [
            'scope' => $scope,
            'year' => $year,
            'months' => $months,
            'yearTotals' => $stats->totals($scope, $from, $until),
            'previousYear' => $stats->totals($scope, $from->copy()->subYear(), $until->copy()->subYearNoOverflow()),
            'categories' => $stats->byCategory($scope, $from, $until)->take(10),
            'currentWeek' => $stats->totals($scope, today()->startOfWeek(), today()),
            'weeks' => MoneyWeeklyReport::query()->orderByDesc('week_start')->paginate(12),
        ]);
    }

    public function show(MoneyWeeklyReport $report): View
    {
        return view('reports.show', [
            'report' => $report,
            'data' => $report->data,
            'previous' => MoneyWeeklyReport::query()->where('week_start', '<', $report->week_start)->orderByDesc('week_start')->first(),
            'next' => MoneyWeeklyReport::query()->where('week_start', '>', $report->week_start)->orderBy('week_start')->first(),
        ]);
    }
}
