<?php

namespace App\Services;

use App\Models\MoneyGoal;
use App\Models\MoneyWeeklyReport;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Bilan de chaque semaine (lundi → dimanche), figé et gardé : gagné, dépensé,
 * soldes, plus grosses dépenses, chiffres des devis et objectifs. Fait chaque
 * lundi matin après la mise à jour depuis l'app de devis.
 */
class MoneyReportService
{
    public function __construct(
        private readonly MoneyStatsService $stats,
        private readonly MoneySyncService $sync,
        private readonly MoneyLockService $lock,
        private readonly Settings $settings,
    ) {}

    /**
     * Mise à jour hebdomadaire complète : devis → Argent, dépenses fixes, bilan de la semaine passée, notification.
     * L'app de devis injoignable n'empêche pas le bilan (l'erreur est notée dans les réglages).
     */
    public function weekly(?Carbon $today = null): ?MoneyWeeklyReport
    {
        $today ??= today();
        if (! $this->lock->isConfigured()) {
            return null;
        }

        try {
            $this->sync->run();
            $this->settings->set(['devis.last_error' => null]);
        } catch (\RuntimeException $e) {
            $this->settings->set(['devis.last_error' => $e->getMessage(), 'devis.last_error_at' => now()->toIso8601String()]);
        }
        $this->sync->runRecurring($today);
        $report = $this->build($today->copy()->startOfWeek()->subWeek());
        $this->notify($report);
        app(MoneyAlertService::class)->check();

        return $report;
    }

    /** Calcule (ou recalcule) le bilan de la semaine qui commence ce lundi. */
    public function build(Carbon $weekStart): MoneyWeeklyReport
    {
        $from = $weekStart->copy()->startOfWeek();
        $to = $from->copy()->endOfWeek()->startOfDay();
        $accounts = $this->stats->accounts();

        $data = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'all' => $this->stats->totals('all', $from, $to),
            'perso' => $this->stats->totals('perso', $from, $to),
            'pro' => $this->stats->totals('pro', $from, $to),
            'balance' => $accounts->sum(fn ($a) => $a->balance($to)),
            'accounts' => $accounts->map(fn ($a) => ['name' => $a->name, 'scope' => $a->scope, 'balance' => $a->balance($to)])->values()->all(),
            'top_expenses' => $this->stats->byCategory('all', $from, $to)->take(5)->map(fn ($c) => ['name' => $c['name'], 'color' => $c['color'], 'amount' => $c['amount']])->all(),
            'quotes' => $this->stats->quotes($from, $to),
            'goals' => MoneyGoal::query()->whereNull('archived_at')->with('account')->get()
                ->map(fn (MoneyGoal $goal) => ['name' => $goal->name, 'kind' => $goal->kind] + array_intersect_key($this->stats->goal($goal), array_flip(['current', 'target', 'percent', 'status'])))
                ->all(),
            'made_at' => now()->toIso8601String(),
        ];

        return MoneyWeeklyReport::query()->updateOrCreate(['week_start' => $from->toDateString()], ['data' => $data]);
    }

    /**
     * Notification « bilan prêt ». Sans montant par défaut : un écran verrouillé
     * ne doit rien montrer ; les montants seulement si demandé dans les réglages Argent.
     */
    public function notify(MoneyWeeklyReport $report): void
    {
        if (! $this->settings->get('argent.weekly_push', true)) {
            return;
        }
        $data = $report->data;
        $period = 'Semaine du '.Carbon::parse($data['from'])->format('d/m').' au '.Carbon::parse($data['to'])->format('d/m');
        $body = $this->settings->get('argent.push_amounts', false)
            ? $period.' : +'.Money::plain($data['all']['income']).' entrés, −'.Money::plain($data['all']['expense']).' sortis ('.($data['all']['net'] >= 0 ? '+' : '').Money::plain($data['all']['net']).').'
            : $period.' : votre bilan est prêt.';
        // Une hausse marquante du mois (en %, jamais de montant) : « 30 % de plus en restaurants… ».
        $trend = app(MoneyTrendService::class)->notable('all')->firstWhere('up', true);
        if ($trend) {
            $body .= ' '.$trend['sentence'];
        }

        app(PushService::class)->send('Bilan de la semaine', $body, route('reports.show', $report), $this->lock->ownerId());
    }
}
