<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Objectif : mettre de côté, encaisser, gagner, ou ne pas dépasser un montant. */
class MoneyGoal extends Model
{
    public const KINDS = [
        'epargne' => 'Mettre de côté (épargne, projet)',
        'encaisse' => 'Encaisser (chiffre des chantiers)',
        'gain' => 'Gagner (entrées − sorties)',
        'depenses' => 'Ne pas dépasser (dépenses)',
    ];

    /** Icônes proposées pour un objectif d'épargne : clé => [libellé, icône]. */
    public const ICONS = [
        'voiture' => ['Voiture', 'car'],
        'maison' => ['Maison, travaux', 'home'],
        'voyage' => ['Voyage, vacances', 'plane'],
        'cadeau' => ['Cadeau, fête', 'gift'],
        'materiel' => ['Matériel, outillage', 'tool'],
        'famille' => ['Famille, enfants', 'heart'],
        'securite' => ['Épargne de précaution', 'shield'],
        'autre' => ['Autre projet', 'target'],
    ];

    public const PERIODS = [
        'mois' => 'Par mois',
        'annee' => 'Par an',
    ];

    public const SCOPES = [
        'all' => 'Perso + pro',
        'perso' => 'Perso',
        'pro' => 'Pro',
    ];

    protected $fillable = ['name', 'icon', 'kind', 'target', 'period', 'scope', 'deadline', 'account_id', 'saved', 'achieved_at', 'archived_at'];

    protected function casts(): array
    {
        return [
            'target' => 'integer', 'saved' => 'integer', 'deadline' => 'date',
            'achieved_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'account_id');
    }

    public function isSaving(): bool
    {
        return $this->kind === 'epargne';
    }

    public function iconName(): string
    {
        return self::ICONS[$this->icon][1] ?? ($this->isSaving() ? 'piggy' : 'target');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
