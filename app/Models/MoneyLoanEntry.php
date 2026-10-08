<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne de « Qui me doit quoi » : prêt, avance, emprunt ou remboursement. */
class MoneyLoanEntry extends Model
{
    /** type => [libellé, signe (+ : on vous doit davantage), sens sur le compte (− : l'argent sort)]. */
    public const TYPES = [
        'pret' => ['Je lui ai prêté ou avancé', 1],
        'rembourse' => ['Il ou elle m\'a remboursé', -1],
        'emprunt' => ['Je lui ai emprunté', -1],
        'je_rembourse' => ['Je l\'ai remboursé', 1],
    ];

    /** Libellés courts de l'historique. */
    public const SHORT = [
        'pret' => 'Prêt / avance',
        'rembourse' => 'Remboursement reçu',
        'emprunt' => 'Emprunt',
        'je_rembourse' => 'Remboursement fait',
    ];

    protected $fillable = ['person_id', 'occurred_on', 'amount', 'type', 'note', 'transaction_id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'amount' => 'integer'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(MoneyPerson::class, 'person_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'transaction_id');
    }

    public function label(): string
    {
        return self::SHORT[$this->type] ?? $this->type;
    }

    /** Effet sur le compte : un prêt fait sortir l'argent, un remboursement reçu le fait entrer. */
    public static function accountAmount(string $type, int $amount): int
    {
        return (self::TYPES[$type][1] ?? 1) > 0 ? -abs($amount) : abs($amount);
    }
}
