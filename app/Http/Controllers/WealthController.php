<?php

namespace App\Http\Controllers;

use App\Models\MoneyPerson;
use App\Services\MoneyWealthService;
use Illuminate\View\View;

/** Patrimoine net et son évolution sur 12 mois. */
class WealthController extends Controller
{
    public function __invoke(MoneyWealthService $wealth): View
    {
        $history = $wealth->history(12);
        $today = $history[count($history) - 1];
        $first = $history[0];
        $people = MoneyPerson::query()->withSum('entries', 'amount')->get()->map(fn (MoneyPerson $p) => $p->balance());

        return view('wealth', [
            'history' => $history,
            'today' => $today,
            'change' => $today['net'] - $first['net'],
            'since' => $first['date'],
            'breakdown' => $wealth->breakdown($today, (int) $people->filter(fn ($b) => $b > 0)->sum(), (int) -$people->filter(fn ($b) => $b < 0)->sum()),
        ]);
    }
}
