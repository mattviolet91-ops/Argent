<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Compte de l'app Argent (compte courant, livret, espèces…), perso ou pro. */
class MoneyAccount extends Model
{
    /** Types de comptes : clé => [libellé, groupe, icône]. */
    public const TYPES = [
        'courant' => ['Compte courant', 'courant', 'bank'],
        'carte' => ['Compte en ligne / prépayé (PayPal, Lydia…)', 'courant', 'card'],
        'especes' => ['Espèces / caisse', 'courant', 'cash'],
        'titres_resto' => ['Titres-restaurant', 'courant', 'meal'],
        'epargne' => ['Livret d\'épargne (Livret A, LDDS, LEP…)', 'epargne', 'piggy'],
        'pel' => ['Épargne logement (PEL, CEL)', 'epargne', 'home'],
        'assurance_vie' => ['Assurance-vie', 'epargne', 'shield'],
        'bourse' => ['Bourse (PEA, compte-titres)', 'epargne', 'chart'],
        'crypto' => ['Crypto-monnaies', 'epargne', 'crypto'],
        'retraite' => ['Épargne retraite (PER)', 'epargne', 'calendar'],
        'objectif' => ['Compte d\'un objectif (argent mis de côté pour un projet)', 'epargne', 'target'],
        'carte_credit' => ['Carte de crédit (débit différé)', 'dette', 'card'],
        'credit' => ['Crédit / prêt (immobilier, auto, conso…)', 'dette', 'loan'],
        'autre' => ['Autre', 'autre', 'wallet'],
    ];

    /** Groupes affichés sur la page Comptes. */
    public const GROUPS = [
        'courant' => 'Comptes courants',
        'epargne' => 'Épargne et placements',
        'dette' => 'Crédits et dettes',
        'autre' => 'Autres',
    ];

    public const SCOPES = [
        'perso' => 'Perso',
        'pro' => 'Pro',
    ];

    protected $fillable = ['name', 'kind', 'scope', 'opening_balance', 'opening_on', 'alert_below', 'color', 'position', 'archived_at'];

    protected function casts(): array
    {
        return ['opening_balance' => 'integer', 'alert_below' => 'integer', 'opening_on' => 'date', 'archived_at' => 'datetime'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class, 'account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Solde à une date : solde de départ + mouvements depuis la date de départ. */
    public function balance(?\DateTimeInterface $at = null): int
    {
        $at ??= today();

        return $this->opening_balance + (int) $this->transactions()
            ->whereDate('occurred_on', '>=', $this->opening_on)
            ->whereDate('occurred_on', '<=', $at)
            ->sum('amount');
    }

    public function kindLabel(): string
    {
        return self::TYPES[$this->kind][0] ?? $this->kind;
    }

    public function group(): string
    {
        return self::TYPES[$this->kind][1] ?? 'autre';
    }

    public function icon(): string
    {
        return self::TYPES[$this->kind][2] ?? 'wallet';
    }

    /** Crédit ou carte à débit différé : le solde est ce qui reste à rembourser (négatif). */
    public function isDebt(): bool
    {
        return $this->group() === 'dette';
    }

    public function scopeLabel(): string
    {
        return self::SCOPES[$this->scope] ?? $this->scope;
    }
}
