<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catégorie de dépense ou de revenu, avec un budget mensuel facultatif. */
class MoneyCategory extends Model
{
    public const TYPES = [
        'expense' => 'Dépense',
        'income' => 'Revenu',
    ];

    public const SCOPES = [
        'both' => 'Perso et pro',
        'perso' => 'Perso',
        'pro' => 'Pro',
    ];

    /** Type de frais de l'app de devis → catégorie synchronisée. */
    public const EXPENSE_KEYS = [
        'materiaux' => 'devis_materiaux',
        'location' => 'devis_location',
        'dechets' => 'devis_dechets',
        'carburant' => 'devis_carburant',
        'outillage' => 'devis_outillage',
        'autre' => 'devis_autre',
    ];

    protected $fillable = ['name', 'type', 'scope', 'color', 'monthly_budget', 'position', 'archived_at'];

    protected function casts(): array
    {
        return ['monthly_budget' => 'integer', 'archived_at' => 'datetime'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }

    public static function system(string $key): ?self
    {
        return self::query()->where('system_key', $key)->first();
    }

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }
}
