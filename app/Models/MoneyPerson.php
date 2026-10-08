<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * « Qui me doit quoi » : une personne (ami, client, associé…) et ce qu'elle vous
 * doit. Solde > 0 : elle vous doit ; < 0 : vous lui devez.
 */
class MoneyPerson extends Model
{
    protected $table = 'money_people';

    public const RELATIONS = [
        'ami' => 'Ami',
        'famille' => 'Famille',
        'client' => 'Client',
        'associe' => 'Associé',
        'autre' => 'Autre',
    ];

    protected $fillable = ['name', 'relation', 'due_on', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'archived_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MoneyLoanEntry::class, 'person_id');
    }

    public function relationLabel(): string
    {
        return self::RELATIONS[$this->relation] ?? $this->relation;
    }

    /** Solde (attribut « balance » calculé par withSum, sinon une requête). */
    public function balance(): int
    {
        return (int) ($this->attributes['entries_sum_amount'] ?? $this->entries()->sum('amount'));
    }

    /** Remboursement attendu et pas encore fait à la date prévue. */
    public function isOverdue(): bool
    {
        return $this->due_on !== null && $this->due_on->isPast() && ! $this->due_on->isToday() && $this->balance() > 0;
    }
}
