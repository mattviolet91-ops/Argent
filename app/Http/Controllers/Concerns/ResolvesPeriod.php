<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait ResolvesPeriod
{
    /** @return array{0: string, 1: Carbon, 2: Carbon} */
    private function period(Request $request, string $default = 'mois'): array
    {
        $period = (string) $request->query('periode', $default);

        if ($period === 'perso' && $request->filled('du') && $request->filled('au')) {
            try {
                $from = Carbon::parse($request->query('du'))->startOfDay();
                $to = Carbon::parse($request->query('au'))->startOfDay();
                if ($from->lte($to)) {
                    return ['perso', $from, $to];
                }
            } catch (\Throwable) {
                // Dates invalides : période par défaut.
            }
        }

        return match ($period) {
            'jour' => ['jour', today(), today()],
            'semaine' => ['semaine', today()->startOfWeek(), today()],
            'annee' => ['annee', today()->startOfYear(), today()],
            'tout' => ['tout', Carbon::create(2000, 1, 1), today()],
            default => ['mois', today()->startOfMonth(), today()],
        };
    }
}
