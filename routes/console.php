<?php

use Illuminate\Support\Facades\Schedule;

// Lancé chaque minute par la tâche cron du serveur (voir docs/INSTALLATION.md).
Schedule::command('app:backup')->dailyAt('01:30');
// Dépenses et revenus fixes du jour.
Schedule::command('app:argent-jour')->dailyAt('06:10');
// Chaque lundi : mise à jour depuis l'app de devis, bilan de la semaine passée, notification.
Schedule::command('app:argent-semaine')->weeklyOn(1, '07:00');
