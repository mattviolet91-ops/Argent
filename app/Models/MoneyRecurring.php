<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Dépense ou revenu qui revient (loyer, abonnement, salaire…) : ajouté tout seul à sa date. */
class MoneyRecurring extends Model
{
    public const FREQUENCIES = [
        'hebdo' => 'Chaque semaine',
        'mensuel' => 'Chaque mois',
        'trimestriel' => 'Chaque trimestre',
        'annuel' => 'Chaque année',
    ];

    protected $fillable = ['label', 'amount', 'account_id', 'category_id', 'frequency', 'next_on', 'active'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'next_on' => 'date', 'active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MoneyCategory::class, 'category_id');
    }

    /** Échéance suivante (le 31 devient le 30 ou le 28 les mois plus courts). */
    public function nextAfter(Carbon $date): Carbon
    {
        return match ($this->frequency) {
            'hebdo' => $date->copy()->addWeek(),
            'trimestriel' => $date->copy()->addMonthsNoOverflow(3),
            'annuel' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    /** Équivalent mensuel (pour le total des dépenses fixes). */
    public function monthlyAmount(): int
    {
        return (int) round(match ($this->frequency) {
            'hebdo' => $this->amount * 52 / 12,
            'trimestriel' => $this->amount / 3,
            'annuel' => $this->amount / 12,
            default => $this->amount,
        });
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }
}
