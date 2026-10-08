<?php

namespace App\Console\Commands;

use App\Services\MoneySyncService;
use Illuminate\Console\Command;

/** Chaque matin : dépenses et revenus fixes du jour (loyer, abonnements…) ajoutés à l'app Argent. */
class MoneyDaily extends Command
{
    protected $signature = 'app:argent-jour';

    protected $description = 'Ajoute les dépenses et revenus fixes arrivés à échéance';

    public function handle(MoneySyncService $sync): int
    {
        $this->info($sync->runRecurring().' mouvement(s) fixe(s) ajouté(s).');

        return self::SUCCESS;
    }
}
