<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Support\Money;

/**
 * Alertes : un budget du mois atteint 80 % ou est dépassé, un compte passe sous
 * son seuil. Affichées sur le résumé tant qu'elles durent, et envoyées une seule
 * fois en notification (une fois par mois et par budget ; une fois par passage
 * sous le seuil, de nouveau après être remonté au-dessus).
 */
class MoneyAlertService
{
    public const WARNING_PERCENT = 80;

    public function __construct(
        private readonly Settings $settings,
        private readonly MoneyStatsService $stats,
        private readonly MoneyLockService $lock,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('alerts.enabled', true);
    }

    public function warnsEarly(): bool
    {
        return (bool) $this->settings->get('alerts.budget_warning', true);
    }

    /**
     * Alertes en cours, les plus graves d'abord.
     *
     * @return list<array{key: string, level: string, title: string, text: string, details: string, url: string}>
     */
    public function current(): array
    {
        $alerts = [];
        $month = today()->format('Y-m');

        foreach ($this->stats->budgets() as $row) {
            $category = $row['category'];
            $level = $row['percent'] >= 100 ? 100 : ($this->warnsEarly() && $row['percent'] >= self::WARNING_PERCENT ? self::WARNING_PERCENT : null);
            if ($level === null) {
                continue;
            }
            $alerts[] = [
                'key' => 'budget:'.$category->id.':'.$level.':'.$month,
                'level' => $level === 100 ? 'danger' : 'warning',
                'title' => $level === 100 ? 'Budget dépassé' : 'Budget presque atteint',
                'text' => $level === 100
                    ? '« '.$category->name.' » a dépassé son budget du mois.'
                    : '« '.$category->name.' » a atteint '.$row['percent'].' % de son budget du mois.',
                'details' => Money::plain($row['spent']).' dépensés sur '.Money::plain($row['budget']).'.',
                'url' => route('categories.index'),
            ];
        }

        foreach (MoneyAccount::query()->active()->whereNotNull('alert_below')->ordered()->get() as $account) {
            $balance = $account->balance();
            if ($balance >= $account->alert_below) {
                continue;
            }
            $alerts[] = [
                'key' => 'low:'.$account->id,
                'level' => 'danger',
                'title' => 'Solde bas',
                'text' => '« '.$account->name.' » est passé sous votre seuil d\'alerte.',
                'details' => 'Solde '.Money::plain($balance).' (seuil '.Money::plain($account->alert_below).').',
                'url' => route('accounts.show', $account),
            ];
        }

        usort($alerts, fn ($a, $b) => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

        return $alerts;
    }

    /** Envoie les nouvelles alertes en notification. Retourne le nombre envoyé. */
    public function check(): int
    {
        if (! $this->enabled() || ! $this->lock->isConfigured()) {
            return 0;
        }

        $month = today()->format('Y-m');
        $alerts = $this->current();
        $activeLow = array_column(array_filter($alerts, fn ($a) => str_starts_with($a['key'], 'low:')), 'key');
        // Gardé : les budgets du mois en cours, et les comptes encore sous leur seuil.
        $before = array_values(array_unique((array) $this->settings->get('alerts.sent', [])));
        $sent = array_values(array_filter($before, fn ($key) => str_starts_with($key, 'low:')
            ? in_array($key, $activeLow, true)
            : str_ends_with($key, ':'.$month)));

        $new = [];
        foreach ($alerts as $alert) {
            if (in_array($alert['key'], $sent, true)) {
                continue;
            }
            // Budget dépassé : l'étape « 80 % » est considérée comme passée (pas de notification après coup).
            if (preg_match('/^budget:(\d+):100:/', $alert['key'], $m)) {
                $sent[] = 'budget:'.$m[1].':'.self::WARNING_PERCENT.':'.$month;
            }
            $sent[] = $alert['key'];
            $new[] = $alert;
        }

        $sent = array_values(array_unique($sent));
        if ($sent !== $before) {
            $this->settings->set(['alerts.sent' => $sent]);
        }

        $showAmounts = (bool) $this->settings->get('argent.push_amounts', false);
        $push = app(PushService::class);
        foreach ($new as $alert) {
            $push->send($alert['title'], $alert['text'].($showAmounts ? ' '.$alert['details'] : ''), $alert['url'], $this->lock->ownerId());
        }

        return count($new);
    }
}
