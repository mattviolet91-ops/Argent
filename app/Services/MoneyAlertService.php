<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyGoal;
use App\Models\MoneyPerson;
use App\Models\MoneyPurchase;
use App\Support\Money;

/**
 * Alertes : un budget du mois atteint 80 % ou est dépassé, un compte passe sous
 * son seuil. Rappels : une garantie se termine dans le mois, un remboursement est
 * attendu (ou en retard). Affichés sur le résumé tant qu'ils durent, et envoyés
 * une seule fois en notification (une fois par mois et par budget ; une fois par
 * passage sous le seuil, de nouveau après être remonté au-dessus ; une fois par
 * garantie et par date de remboursement).
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

    /** Rappels de fin de garantie et de remboursement. */
    public function remindersEnabled(): bool
    {
        return (bool) $this->settings->get('alerts.reminders', true);
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
            $balance = $this->stats->balanceOf($account);
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

        $alerts = [...$alerts, ...$this->reminders()];
        usort($alerts, fn ($a, $b) => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

        return $alerts;
    }

    /** Envoie les nouvelles alertes en notification. Retourne le nombre envoyé. */
    public function check(): int
    {
        if (! $this->lock->isConfigured()) {
            return 0;
        }
        $month = today()->format('Y-m');
        $current = $this->current();
        // Objectif atteint : toujours signalé (ce n'est pas une alerte). Alertes et rappels : selon les réglages.
        $alerts = array_values(array_filter($current, fn ($a) => self::isReminder($a['key']) ? $this->remindersEnabled() : $this->enabled()));
        // Gardé : les budgets du mois en cours, et ce qui dure encore (compte sous son seuil, garantie, remboursement).
        $active = array_column($current, 'key');
        $before = array_values(array_unique((array) $this->settings->get('alerts.sent', [])));
        $sent = array_values(array_filter($before, fn ($key) => in_array($key, $active, true)
            || (str_starts_with($key, 'budget:') && str_ends_with($key, ':'.$month))));

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

        return count($new) + $this->goalsReached($push);
    }

    public static function isReminder(string $key): bool
    {
        return str_starts_with($key, 'warranty:') || str_starts_with($key, 'loan:');
    }

    /**
     * Garanties qui se terminent dans le mois, remboursements attendus d'ici 3 jours ou en retard.
     *
     * @return list<array{key: string, level: string, title: string, text: string, details: string, url: string}>
     */
    private function reminders(): array
    {
        $alerts = [];
        $purchases = MoneyPurchase::query()->whereNotNull('warranty_until')
            ->whereDate('warranty_until', '>=', today())->whereDate('warranty_until', '<=', today()->addDays(MoneyPurchase::NOTICE_DAYS))
            ->orderBy('warranty_until')->get();
        foreach ($purchases as $purchase) {
            $days = (int) $purchase->daysLeft();
            $alerts[] = [
                'key' => 'warranty:'.$purchase->id.':'.$purchase->warranty_until->toDateString(),
                'level' => 'warning',
                'title' => 'Fin de garantie',
                'text' => '« '.$purchase->name.' » n\'est plus garanti après le '.$purchase->warranty_until->format('d/m/Y')
                    .' ('.($days === 0 ? 'aujourd\'hui' : 'dans '.$days.' jour'.($days > 1 ? 's' : '')).'). Un souci ? C\'est le moment.',
                'details' => $purchase->amount ? 'Acheté '.Money::plain($purchase->amount).($purchase->shop ? ' chez '.$purchase->shop : '').'.' : '',
                'url' => route('purchases.show', $purchase),
            ];
        }

        $people = MoneyPerson::query()->whereNull('archived_at')->whereNotNull('due_on')
            ->whereDate('due_on', '<=', today()->addDays(3))->withSum('entries', 'amount')->orderBy('due_on')->get();
        foreach ($people as $person) {
            $balance = $person->balance();
            if ($balance <= 0) {
                continue;
            }
            $late = $person->due_on->lt(today());
            $alerts[] = [
                'key' => 'loan:'.$person->id.':'.$person->due_on->toDateString(),
                'level' => $late ? 'danger' : 'warning',
                'title' => $late ? 'Remboursement en retard' : 'Remboursement attendu',
                'text' => $person->name.($late
                    ? ' devait vous rembourser le '.$person->due_on->format('d/m/Y').'.'
                    : ($person->due_on->isToday() ? ' doit vous rembourser aujourd\'hui.' : ' doit vous rembourser d\'ici le '.$person->due_on->format('d/m/Y').'.')),
                'details' => 'Reste '.Money::plain($balance).'.',
                'url' => route('loans.show', $person),
            ];
        }

        return $alerts;
    }

    /** Objectifs d'épargne qui viennent d'atteindre leur montant : bravo (une seule fois). */
    private function goalsReached(PushService $push): int
    {
        $count = 0;
        foreach (MoneyGoal::query()->where('kind', 'epargne')->whereNull('achieved_at')->whereNull('archived_at')->with('account')->get() as $goal) {
            if ($this->stats->goal($goal)['percent'] < 100) {
                continue;
            }
            $goal->update(['achieved_at' => now()]);
            $push->send('Objectif atteint 🎉', '« '.$goal->name.' » : le montant est réuni. Bravo !', route('goals.show', $goal), $this->lock->ownerId());
            $count++;
        }

        return $count;
    }
}
