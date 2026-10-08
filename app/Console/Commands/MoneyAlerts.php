<?php

namespace App\Console\Commands;

use App\Services\MoneyAlertService;
use Illuminate\Console\Command;

/** Chaque matin : budgets dépassés et soldes bas (après les dépenses fixes du jour). */
class MoneyAlerts extends Command
{
    protected $signature = 'app:argent-alertes';

    protected $description = 'Envoie les alertes de budget et de solde bas';

    public function handle(MoneyAlertService $alerts): int
    {
        $this->info($alerts->check().' alerte(s) envoyée(s).');

        return self::SUCCESS;
    }
}
