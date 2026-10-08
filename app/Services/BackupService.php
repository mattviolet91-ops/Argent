<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Sauvegardes de la base : copie complète chaque nuit dans le dossier privé,
 * les 30 dernières gardées. SQLite : copie du fichier ; autre base : export JSON des tables.
 */
class BackupService
{
    public const DIR = 'backups';

    public const KEEP = 30;

    /** Crée une sauvegarde et retourne son nom. */
    public function create(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $stamp = now()->format('Y-m-d-His');

        if (DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() !== ':memory:') {
            $name = 'argent-'.$stamp.'.sqlite';
            // VACUUM INTO : copie cohérente même si l'app est utilisée au même moment.
            DB::statement('VACUUM INTO ?', [$disk->path(self::DIR.'/'.$name)]);

            return $name;
        }

        $name = 'argent-'.$stamp.'.json';
        $tables = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)) {
                $tables[$table] = DB::table($table)->get();
            }
        }
        $disk->put(self::DIR.'/'.$name, json_encode(['made_at' => now()->toIso8601String(), 'tables' => $tables], JSON_UNESCAPED_UNICODE));

        return $name;
    }

    /** Garde les 30 plus récentes. */
    public function prune(): int
    {
        $old = $this->list()->slice(self::KEEP);
        foreach ($old as $backup) {
            Storage::disk('local')->delete(self::DIR.'/'.$backup['name']);
        }

        return $old->count();
    }

    /** @return Collection<int, array{name: string, size: int, date: int}> plus récentes d'abord */
    public function list(): Collection
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->filter(fn ($path) => preg_match('/^argent-[\d-]+\.(sqlite|json)$/', basename($path)))
            ->map(fn ($path) => ['name' => basename($path), 'size' => $disk->size($path), 'date' => $disk->lastModified($path)])
            ->sortByDesc('name')->values();
    }
}
