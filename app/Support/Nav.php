<?php

namespace App\Support;

/**
 * Menu de l'app, rangé en sections. Les onglets en haut de chaque page montrent
 * les pages de sa section ; le menu complet (barre de côté, « Plus ») montre tout.
 */
final class Nav
{
    /** @return array<string, list<array{0: string, 1: string, 2: string, 3: string}>> section => [route, libellé, icône, pages] */
    public static function sections(): array
    {
        return [
            'Au quotidien' => [
                ['dashboard', 'Résumé', 'home', 'dashboard'],
                ['transactions.index', 'Mouvements', 'wallet', 'transactions.*'],
                ['calendar', 'Calendrier', 'calendar', 'calendar'],
                ['accounts.index', 'Comptes', 'piggy', 'accounts.*'],
                ['categories.index', 'Budgets', 'chart', 'categories.*'],
                ['recurrings.index', 'Fixes', 'repeat', 'recurrings.*'],
            ],
            'Projets' => [
                ['goals.index', 'Objectifs', 'target', 'goals.*'],
                ['tags.index', 'Chantiers et projets', 'tag', 'tags.*'],
                ['claims.index', 'Notes de frais', 'receipt', 'claims.*'],
                ['loans.index', 'Qui me doit quoi', 'users', 'loans.*'],
                ['purchases.index', 'Garanties', 'shield', 'purchases.*'],
            ],
            'Analyses' => [
                ['trends', 'Tendances', 'trend', 'trends'],
                ['reports.index', 'Bilans', 'book', 'reports.*'],
                ['wealth', 'Patrimoine', 'bank', 'wealth'],
                ['credits.index', 'Crédits', 'loan', 'credits.*'],
                ['afford', 'Puis-je me le permettre ?', 'help', 'afford'],
            ],
            'Outils' => [
                ['import.create', 'Importer un relevé', 'upload', 'import.*'],
                ['settings', 'Réglages', 'settings', 'settings'],
            ],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    public static function all(): array
    {
        return array_merge(...array_values(self::sections()));
    }

    /** Toutes les pages de l'app (pour savoir si l'app est ouverte). */
    public static function patterns(): array
    {
        return array_column(self::all(), 3);
    }

    /** Pages de la section de la page affichée (onglets). */
    public static function current(): array
    {
        foreach (self::sections() as $items) {
            if (request()->routeIs(...array_column($items, 3))) {
                return $items;
            }
        }

        return self::sections()['Au quotidien'];
    }

    /** Pages rangées sous « Plus » dans la barre du bas (hors Résumé, Mouvements, Objectifs). */
    public static function morePatterns(): array
    {
        return array_values(array_diff(self::patterns(), ['dashboard', 'transactions.*', 'goals.*']));
    }
}
