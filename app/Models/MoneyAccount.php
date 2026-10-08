<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Compte de l'app Argent (compte courant, livret, espèces…), perso ou pro. */
class MoneyAccount extends Model
{
    public const KINDS = [
        'courant' => 'Compte courant',
        'epargne' => 'Épargne (livret…)',
        'especes' => 'Espèces',
        'carte' => 'Carte / compte en ligne',
        'autre' => 'Autre',
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
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function scopeLabel(): string
    {
        return self::SCOPES[$this->scope] ?? $this->scope;
    }
}
