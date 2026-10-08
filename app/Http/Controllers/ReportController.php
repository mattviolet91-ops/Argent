<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyWeeklyReport;
use App\Services\ActivityLogger;
use App\Services\MoneyMonthReportService;
use App\Services\MoneyStatsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
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
        // Les mêmes mois l'an d'avant, pour comparer.
        $before = collect($stats->monthly($scope, 12, today()->setDate($year - 1, 12, 31)))->keyBy(fn ($m) => $m['month']->month);

        return view('reports.index', [
            'scope' => $scope,
            'year' => $year,
            'months' => $months,
            'before' => $before->filter(fn ($m) => $m['income'] || $m['expense']),
            'yearTotals' => $stats->totals($scope, $from, $until),
            'previousYear' => $stats->totals($scope, $from->copy()->subYear(), $until->copy()->subYearNoOverflow()),
            'categories' => $stats->byCategory($scope, $from, $until)->take(10),
            'currentWeek' => $stats->totals($scope, today()->startOfWeek(), today()),
            'weeks' => MoneyWeeklyReport::query()->orderByDesc('week_start')->paginate(12),
        ]);
    }

    /** Bilan d'un mois en PDF : ?mois=2026-10&vue=pro&detail=1. */
    public function pdf(Request $request, MoneyMonthReportService $reports): Response
    {
        $value = (string) $request->query('mois', today()->format('Y-m'));
        $month = preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12
            ? Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay()
            : today()->startOfMonth();
        abort_if($month->isAfter(today()) || $month->year < 2000, 404);
        $scope = in_array($request->query('vue'), ['all', 'perso', 'pro'], true) ? (string) $request->query('vue') : 'all';
        $name = 'bilan-argent-'.$month->format('Y-m').($scope !== 'all' ? '-'.$scope : '').'.pdf';
        ActivityLogger::log('argent.pdf', 'Bilan PDF de '.$month->format('m/Y').' téléchargé');

        return response($reports->pdf($month, $scope, $request->boolean('detail')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('telecharger') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
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
