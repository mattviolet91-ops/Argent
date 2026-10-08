<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsMoneyInput;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyTransaction;
use App\Services\MoneyForecastService;
use App\Services\MoneyStatsService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * « Puis-je me le permettre ? » : un achat (en une ou plusieurs fois) et ses effets :
 * le solde le plus bas à venir sur le compte, le budget du mois, l'épargne habituelle.
 * Rien n'est enregistré.
 */
class AffordController extends Controller
{
    use ReadsMoneyInput;

    public const INSTALMENTS = [1 => 'En une fois', 3 => 'En 3 fois', 4 => 'En 4 fois', 10 => 'En 10 fois'];

    public function __invoke(Request $request, MoneyForecastService $forecast, MoneyStatsService $stats): View
    {
        $accounts = $this->accountOptions()->filter(fn (MoneyAccount $a) => in_array($a->group(), ['courant'], true))->values();
        $accounts = $accounts->isNotEmpty() ? $accounts : $this->accountOptions();
        $account = $accounts->firstWhere('id', $request->integer('compte')) ?? $accounts->firstWhere('scope', 'perso') ?? $accounts->first();
        $amount = Money::parse(str_replace(['−', '–'], '-', (string) $request->query('montant', '')));
        $amount = $amount !== null && $amount > 0 ? $amount : null;
        $times = array_key_exists($request->integer('fois'), self::INSTALMENTS) ? $request->integer('fois') : 1;
        $date = $this->date((string) $request->query('le', ''));
        $category = $request->integer('categorie') ? MoneyCategory::query()->where('type', 'expense')->find($request->integer('categorie')) : null;

        $result = $amount && $account ? $this->simulate($account, $amount, $times, $date, $category, $forecast, $stats) : null;

        return view('afford', [
            'accounts' => $accounts,
            'account' => $account,
            'amount' => $amount,
            'times' => $times,
            'date' => $date,
            'category' => $category,
            'categories' => MoneyCategory::query()->active()->where('type', 'expense')->ordered()->get(),
            'result' => $result,
        ]);
    }

    /** @return array<string, mixed> */
    private function simulate(MoneyAccount $account, int $amount, int $times, Carbon $date, ?MoneyCategory $category, MoneyForecastService $forecast, MoneyStatsService $stats): array
    {
        // Paiements de l'achat : une fois, ou chaque mois (le 1er paiement à la date choisie).
        $payments = collect();
        $each = intdiv($amount, $times);
        for ($i = 0; $i < $times; $i++) {
            $payments->push([
                'date' => $date->copy()->addMonthsNoOverflow($i),
                'amount' => -($i === 0 ? $amount - $each * ($times - 1) : $each),
                'label' => 'Achat'.($times > 1 ? ' ('.($i + 1).'/'.$times.')' : ''),
            ]);
        }
        $until = $payments->last()['date']->copy()->max(today()->addMonthNoOverflow()->endOfMonth())->addDays(5);
        $start = $stats->balanceOf($account);
        $events = $forecast->events($account, $until);
        // Achat à une date déjà passée : compté comme s'il était fait aujourd'hui.
        $payments = $payments->map(fn ($p) => ['date' => $p['date']->lte(today()) ? today()->addDay() : $p['date']] + $p);
        $without = $forecast->project($start, $events, $until);
        $with = $forecast->project($start, $events->merge($payments), $until);

        // Budget du mois de l'achat.
        $budget = null;
        if ($category && $category->monthly_budget) {
            $month = $payments->first()['date'];
            $spent = (int) -MoneyTransaction::query()->real()->where('category_id', $category->id)
                ->betweenDates($month->copy()->startOfMonth(), $month->copy()->endOfMonth())->sum('amount');
            $budget = ['name' => $category->name, 'spent' => $spent, 'after' => $spent + abs($payments->first()['amount']), 'budget' => $category->monthly_budget];
        }

        // Ce qui reste en moyenne chaque mois (3 derniers mois complets).
        $months = array_slice($stats->monthly('all', 4), 0, 3);
        $saved = (int) round(array_sum(array_column($months, 'net')) / 3);

        $threshold = $account->alert_below;
        $level = match (true) {
            $with['min'] < 0 => 'danger',
            ($threshold !== null && $with['min'] < $threshold) || ($budget && $budget['after'] > $budget['budget']) || ($saved > 0 && $amount > $saved * 3) => 'warning',
            default => 'success',
        };

        return [
            'payments' => $payments,
            'without' => $without,
            'with' => $with,
            'start' => $start,
            'budget' => $budget,
            'saved' => $saved,
            'months_of_savings' => $saved > 0 ? round($amount / $saved, 1) : null,
            'forecast_without' => $stats->endOfMonthForecast('all'),
            'this_month' => (int) -$payments->filter(fn ($p) => $p['date']->isSameMonth(today()))->sum('amount'),
            'threshold' => $threshold,
            'level' => $level,
        ];
    }

    private function date(string $value): Carbon
    {
        try {
            $date = $value !== '' ? Carbon::createFromFormat('!Y-m-d', $value) : null;
        } catch (\Throwable) {
            $date = null;
        }

        return $date && $date->gte(today()->subYear()) && $date->lte(today()->addYears(2)) ? $date : today();
    }
}
