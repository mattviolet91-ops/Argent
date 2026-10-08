<?php

namespace App\Services;

use App\Models\MoneyAccount;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Solde à venir d'un compte, jour par jour : solde d'aujourd'hui, mouvements déjà
 * notés pour plus tard, et échéances des dépenses, revenus et virements fixes.
 */
class MoneyForecastService
{
    public function __construct(private readonly MoneyStatsService $stats) {}

    /**
     * Ce qui est prévu sur le compte d'ici la date (après aujourd'hui).
     *
     * @return Collection<int, array{date: Carbon, amount: int, label: string}>
     */
    public function events(MoneyAccount $account, Carbon $until): Collection
    {
        $events = MoneyTransaction::query()->where('account_id', $account->id)
            ->whereDate('occurred_on', '>', today())->whereDate('occurred_on', '<=', $until)
            ->get(['occurred_on', 'amount', 'label'])->toBase()
            ->map(fn (MoneyTransaction $t) => ['date' => $t->occurred_on->copy(), 'amount' => $t->amount, 'label' => $t->label]);

        foreach ($this->stats->occurrences(today()->addDay(), $until) as $item) {
            /** @var MoneyRecurring $recurring */
            $recurring = $item['recurring'];
            if ($recurring->account_id === $account->id) {
                $events->push(['date' => $item['date'], 'amount' => $recurring->isTransfer() ? -abs($recurring->amount) : $recurring->amount, 'label' => $recurring->label]);
            }
            if ($recurring->to_account_id === $account->id) {
                $events->push(['date' => $item['date'], 'amount' => abs($recurring->amount), 'label' => $recurring->label]);
            }
        }

        return $events->sortBy(fn ($e) => $e['date']->timestamp)->values();
    }

    /**
     * Solde de chaque jour, d'aujourd'hui à la date, et le point le plus bas.
     *
     * @param  Collection<int, array{date: Carbon, amount: int, label: string}>  $events
     * @return array{days: list<array{date: Carbon, balance: int}>, min: int, min_on: Carbon, min_label: ?string, end: int}
     */
    public function project(int $start, Collection $events, Carbon $until): array
    {
        $byDay = $events->groupBy(fn ($e) => $e['date']->toDateString());
        $balance = $start;
        $days = [['date' => today(), 'balance' => $balance]];
        [$min, $minOn, $minLabel] = [$balance, today(), null];
        for ($date = today()->addDay(); $date->lte($until); $date->addDay()) {
            $today = $byDay->get($date->toDateString(), collect());
            $balance += (int) $today->sum('amount');
            $days[] = ['date' => $date->copy(), 'balance' => $balance];
            if ($balance < $min) {
                [$min, $minOn] = [$balance, $date->copy()];
                $minLabel = $today->sortBy('amount')->first()['label'] ?? null;
            }
        }

        return ['days' => $days, 'min' => $min, 'min_on' => $minOn, 'min_label' => $minLabel, 'end' => $balance];
    }
}
