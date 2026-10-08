<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Services\PushService;
use Illuminate\Console\Command;

/** Appelé par scripts/deploy.sh après une mise à jour automatique du serveur. */
class Deployed extends Command
{
    protected $signature = 'app:deployed {message? : Dernière modification installée} {--echec : La mise à jour a échoué}';

    protected $description = 'Prévient que l\'app a été mise à jour (ou que la mise à jour a échoué)';

    public function handle(PushService $push): int
    {
        if ($this->option('echec')) {
            ActivityLogger::log('app.deploy_failed', 'Échec de la mise à jour automatique');
            $push->send('Argent : mise à jour en échec', 'L\'ancienne version reste en place (journal : argent-deploy.log).', route('dashboard'));

            return self::SUCCESS;
        }

        $message = trim((string) $this->argument('message'));
        ActivityLogger::log('app.deployed', 'App mise à jour');
        // Le titre de la modification peut parler d'argent : la notification reste neutre.
        $push->send('Argent mis à jour', 'Les dernières améliorations sont installées.', route('dashboard'));
        $this->info($message ?: 'OK');

        return self::SUCCESS;
    }
}
