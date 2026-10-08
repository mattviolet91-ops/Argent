<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/** Chaque nuit : copie de la base (gardée 30 jours, téléchargeable dans les réglages). */
class Backup extends Command
{
    protected $signature = 'app:backup';

    protected $description = 'Sauvegarde la base de l\'app Argent';

    public function handle(BackupService $backups): int
    {
        $this->info('Sauvegarde : '.$backups->create());
        $this->info($backups->prune().' ancienne(s) sauvegarde(s) supprimée(s).');

        return self::SUCCESS;
    }
}
