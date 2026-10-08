<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Détection des abonnements : les dépenses qui reviennent (même commerçant, même
 * montant à quelques % près, à intervalle régulier) dans les relevés et les
 * mouvements saisis, et qui ne sont pas encore dans les Fixes.
 */
class MoneySubscriptionService
{
    /** fréquence => [écart habituel en jours, écart mini, écart maxi, nombre de fois minimum]. */
    private const RHYTHMS = [
        'hebdo' => [7, 5, 9, 4],
        'mensuel' => [30, 24, 37, 3],
        'trimestriel' => [91, 75, 105, 3],
        'annuel' => [365, 335, 395, 2],
    ];

    private const DISMISSED = 'subscriptions.dismissed';

    /** @var array<string, ?string> mot-clé de chaque libellé déjà lu */
    private array $keywords = [];

    public function __construct(
        private readonly MoneyImportService $importer,
        private readonly Settings $settings,
    ) {}

    /**
     * Abonnements repérés, les plus chers (par mois) d'abord.
     *
     * @return Collection<int, array{key: string, label: string, keyword: string, account: MoneyAccount, category_id: ?int, amount: int, frequency: string, count: int, last_on: Carbon, next_on: Carbon, monthly: int, ids: list<int>}>
     */
    public function suggestions(?Carbon $today = null): Collection
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $rows = MoneyTransaction::query()->real()->where('amount', '<', 0)->whereNull('recurring_id')
            ->whereIn('source', ['import', 'manual'])
            ->whereDate('occurred_on', '>=', $today->copy()->subMonths(13))->whereDate('occurred_on', '<=', $today)
            ->orderBy('occurred_on')->get(['id', 'account_id', 'category_id', 'occurred_on', 'amount', 'label', 'source']);

        $dismissed = array_flip((array) $this->settings->get(self::DISMISSED, []));
        $known = MoneyRecurring::query()->whereNull('to_account_id')->get();
        $knownKeywords = $known->map(fn (MoneyRecurring $r) => $this->keyword($r->label))->filter()->flip();
        $accounts = MoneyAccount::query()->active()->get()->keyBy('id');

        $found = collect();
        $groups = $rows->groupBy(fn (MoneyTransaction $t) => $t->account_id.'|'.($this->keyword($t->label) ?? ''));
        foreach ($groups as $groupKey => $group) {
            [$accountId, $keyword] = explode('|', (string) $groupKey, 2);
            $account = $accounts->get((int) $accountId);
            if ($keyword === '' || ! $account || isset($knownKeywords[$keyword]) || $group->count() < 2) {
                continue;
            }
            foreach ($this->clusters($group) as $cluster) {
                // Une fois ou plus de 2 fois par semaine : pas un abonnement.
                if ($cluster->count() < 2 || $cluster->count() > 120) {
                    continue;
                }
                $suggestion = $this->rhythm($cluster, $today);
                if (! $suggestion) {
                    continue;
                }
                $key = $accountId.'|'.$keyword.'|'.$suggestion['frequency'];
                $amount = (int) $cluster->last()->amount;
                // Déjà dans les Fixes sous un autre nom : même compte, même rythme, même montant (± 3 %).
                $already = $known->contains(fn (MoneyRecurring $r) => $r->account_id === (int) $accountId
                    && $r->frequency === $suggestion['frequency'] && abs($r->amount - $amount) <= max(50, abs($amount) * 0.03));
                if ($already || isset($dismissed[$key])) {
                    continue;
                }
                $latest = $cluster->last();
                $found->push([
                    'key' => $key,
                    'label' => $latest->source === 'manual' && mb_strlen($latest->label) <= 40 ? $latest->label : self::pretty($keyword),
                    'keyword' => $keyword,
                    'account' => $account,
                    'category_id' => $cluster->pluck('category_id')->filter()->countBy()->sortDesc()->keys()->first(),
                    'amount' => $amount,
                    'frequency' => $suggestion['frequency'],
                    'count' => $cluster->count(),
                    'last_on' => $latest->occurred_on->copy(),
                    'next_on' => $suggestion['next_on'],
                    'monthly' => (new MoneyRecurring(['amount' => $amount, 'frequency' => $suggestion['frequency']]))->monthlyAmount(),
                    'ids' => $cluster->pluck('id')->all(),
                ]);
            }
        }

        return $found->sortBy('monthly')->values();
    }

    /** « netflix com » → « Netflix ». */
    private static function pretty(string $keyword): string
    {
        $words = array_filter(explode(' ', $keyword), fn ($w) => ! in_array($w, ['com', 'fr', 'net', 'www', 'eu', 'org'], true));

        return Str::title(implode(' ', $words) ?: $keyword);
    }

    /**
     * Nombre d'abonnements repérés, pour le résumé : recalculé seulement quand les
     * mouvements, les Fixes ou la liste des ignorés changent (ou le lendemain).
     */
    public function count(): int
    {
        $signature = implode('|', [
            today()->toDateString(),
            MoneyTransaction::query()->count(), MoneyTransaction::query()->max('updated_at'),
            MoneyRecurring::query()->count(), MoneyRecurring::query()->max('updated_at'),
            md5(json_encode($this->settings->get(self::DISMISSED, []))),
        ]);

        return (int) Cache::remember('argent.subscriptions.'.md5($signature), now()->addDay(), fn () => $this->suggestions()->count());
    }

    private function keyword(string $label): ?string
    {
        return array_key_exists($label, $this->keywords) ? $this->keywords[$label] : $this->keywords[$label] = $this->importer->keyword($label);
    }

    public function find(string $key): ?array
    {
        return $this->suggestions()->firstWhere('key', $key);
    }

    /** « Ce n'est pas un abonnement » : ne plus le proposer. */
    public function dismiss(string $key): void
    {
        $list = array_values(array_unique([...(array) $this->settings->get(self::DISMISSED, []), $key]));
        $this->settings->set([self::DISMISSED => array_slice($list, -300)]);
    }

    /**
     * Même commerçant mais montants différents (ex. deux abonnements chez le même) : groupes de montants proches.
     *
     * @param  Collection<int, MoneyTransaction>  $group
     * @return list<Collection<int, MoneyTransaction>>
     */
    private function clusters(Collection $group): array
    {
        $clusters = [];
        foreach ($group->sortBy('amount') as $t) {
            $last = array_key_last($clusters);
            $reference = $last !== null ? $clusters[$last]->first()->amount : null;
            if ($reference !== null && abs($t->amount - $reference) <= max(100, abs($reference) * 0.08)) {
                $clusters[$last]->push($t);
            } else {
                $clusters[] = collect([$t]);
            }
        }

        return array_map(fn (Collection $c) => $c->sortBy(fn ($t) => $t->occurred_on->timestamp)->values(), $clusters);
    }

    /**
     * Rythme régulier et encore en cours ? Fréquence et prochaine date.
     *
     * @param  Collection<int, MoneyTransaction>  $cluster
     * @return array{frequency: string, next_on: Carbon}|null
     */
    private function rhythm(Collection $cluster, Carbon $today): ?array
    {
        $dates = $cluster->map(fn ($t) => $t->occurred_on->copy())->values();
        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = (int) $dates[$i - 1]->diffInDays($dates[$i]);
        }
        if ($gaps === []) {
            return null;
        }
        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        foreach (self::RHYTHMS as $frequency => [$usual, $min, $max, $times]) {
            if ($median < $min || $median > $max || $dates->count() < $times) {
                continue;
            }
            // Les derniers écarts doivent tous être réguliers, et le dernier passage récent.
            $recent = array_slice(array_map(fn ($i) => (int) $dates[$i - 1]->diffInDays($dates[$i]), range(1, $dates->count() - 1)), -5);
            if (array_filter($recent, fn ($gap) => $gap < $min || $gap > $max) !== []) {
                return null;
            }
            if ($dates->last()->diffInDays($today) > $usual * 1.6) {
                return null;
            }
            $recurring = new MoneyRecurring(['frequency' => $frequency]);
            $next = $recurring->nextAfter($dates->last());
            for ($i = 0; $i < 60 && $next->lte($today); $i++) {
                $next = $recurring->nextAfter($next);
            }

            return ['frequency' => $frequency, 'next_on' => $next];
        }

        return null;
    }
}
