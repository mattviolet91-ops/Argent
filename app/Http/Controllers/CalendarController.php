<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyPerson;
use App\Models\MoneyPurchase;
use App\Models\MoneyTransaction;
use App\Services\MoneyStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Calendrier : le mois jour par jour, avec ce qui est sorti et entré chaque jour,
 * et ce qui arrive (dépenses fixes, dépôts prévus, fins de garantie, remboursements attendus).
 */
class CalendarController extends Controller
{
    use ReadsMoneyInput;

    public function __invoke(Request $request, MoneyStatsService $stats): View
    {
        $scope = $this->scope($request);
        $month = $this->month((string) $request->query('mois', ''));
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        // Mouvements du mois, rangés par jour (les virements entre comptes à part).
        $transactions = MoneyTransaction::query()->inScope($scope)->betweenDates($from, $to)
            ->with(['account', 'category'])->orderBy('occurred_on')->orderBy('id')->get()
            ->groupBy(fn (MoneyTransaction $t) => $t->occurred_on->toDateString());

        // À venir : échéances des fixes (après aujourd'hui : avant, elles sont déjà notées).
        $events = collect();
        $start = $from->copy()->max(today()->addDay());
        if ($start->lte($to)) {
            foreach ($stats->occurrences($start, $to) as $item) {
                $recurring = $item['recurring'];
                $effect = $recurring->effectOn($scope);
                if ($scope !== 'all' && $effect === 0 && ! $recurring->isTransfer()) {
                    continue;
                }
                $events->push([
                    'date' => $item['date'], 'kind' => $recurring->isTransfer() ? 'transfer' : ($recurring->amount < 0 ? 'expense' : 'income'),
                    'label' => $recurring->label.($recurring->isTransfer() ? ' → '.$recurring->toAccount?->name : ''),
                    'amount' => $recurring->amount, 'signed' => ! $recurring->isTransfer(),
                    'url' => route('recurrings.index'), 'tag' => 'Fixe',
                ]);
            }
        }
        foreach (MoneyPurchase::query()->whereBetween('warranty_until', [$from->toDateString(), $to->toDateString()])->get() as $purchase) {
            $events->push([
                'date' => $purchase->warranty_until, 'kind' => 'warranty', 'label' => 'Fin de garantie · '.$purchase->name,
                'amount' => null, 'signed' => false, 'url' => route('purchases.show', $purchase), 'tag' => 'Garantie',
            ]);
        }
        foreach (MoneyPerson::query()->whereNull('archived_at')->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])->withSum('entries', 'amount')->get() as $person) {
            if ($person->balance() <= 0) {
                continue;
            }
            $events->push([
                'date' => $person->due_on, 'kind' => 'loan', 'label' => $person->name.($person->isOverdue() ? ' devait vous rembourser' : ' doit vous rembourser'),
                'amount' => $person->balance(), 'signed' => false, 'url' => route('loans.show', $person), 'tag' => 'Remboursement',
            ]);
        }
        $events = $events->groupBy(fn ($event) => $event['date']->toDateString());

        $days = $this->days($from, $to, $transactions, $events);
        $selected = $this->selected((string) $request->query('jour', ''), $from, $to);
        $past = $transactions->flatten()->filter(fn (MoneyTransaction $t) => ! $t->isTransfer() && $t->occurred_on->lte(today()));
        $comingOut = $events->flatten(1)->filter(fn ($e) => $e['kind'] === 'expense')->sum(fn ($e) => -$e['amount']);
        $comingIn = $events->flatten(1)->filter(fn ($e) => $e['kind'] === 'income')->sum('amount');

        return view('calendar', [
            'scope' => $scope,
            'month' => $from,
            'previous' => $from->copy()->subMonthNoOverflow(),
            'next' => $from->copy()->addMonthNoOverflow(),
            'weeks' => array_chunk($days, 7),
            'selected' => $selected,
            'dayTransactions' => $selected ? $transactions->get($selected->toDateString(), collect()) : collect(),
            'dayEvents' => $selected ? $events->get($selected->toDateString(), collect()) : collect(),
            'spent' => (int) -$past->where('amount', '<', 0)->sum('amount'),
            'earned' => (int) $past->where('amount', '>', 0)->sum('amount'),
            'comingOut' => (int) $comingOut,
            'comingIn' => (int) $comingIn,
            'maxSpent' => max(1, (int) collect($days)->max('spent')),
            'upcoming' => $events->flatten(1)->filter(fn ($e) => $e['date']->gte(today()))->sortBy(fn ($e) => $e['date']->timestamp)->values(),
        ]);
    }

    /** « 2026-10 » → 1er du mois (mois en cours si invalide, de 2000 à dans 2 ans). */
    private function month(string $value): Carbon
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $month = Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
            if ($month->year >= 2000 && $month->lte(today()->addYears(2))) {
                return $month;
            }
        }

        return today()->startOfMonth();
    }

    private function selected(string $value, Carbon $from, Carbon $to): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                $day = Carbon::createFromFormat('!Y-m-d', $value);
                if ($day && $day->betweenIncluded($from, $to)) {
                    return $day;
                }
            } catch (\Throwable) {
                // Date invalide : jour par défaut.
            }
        }

        return today()->betweenIncluded($from, $to) ? today() : null;
    }

    /**
     * Cases du calendrier (semaines du lundi au dimanche ; null = case vide).
     *
     * @param  Collection<string, Collection<int, MoneyTransaction>>  $transactions
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $events
     * @return list<array{date: Carbon, spent: int, earned: int, events: int, planned: bool}|null>
     */
    private function days(Carbon $from, Carbon $to, Collection $transactions, Collection $events): array
    {
        $days = array_fill(0, $from->dayOfWeekIso - 1, null);
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $real = $transactions->get($key, collect())->reject(fn (MoneyTransaction $t) => $t->isTransfer());
            $days[] = [
                'date' => $date->copy(),
                'spent' => (int) -$real->where('amount', '<', 0)->sum('amount'),
                'earned' => (int) $real->where('amount', '>', 0)->sum('amount'),
                'events' => $events->get($key, collect())->count(),
                'planned' => $transactions->has($key) && $date->isAfter(today()),
            ];
        }
        while (count($days) % 7 !== 0) {
            $days[] = null;
        }

        return $days;
    }
}
