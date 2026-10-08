<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mouvement d'argent : montant signé (+ entrée, − sortie). Un virement entre deux
 * comptes donne deux lignes (« transfer ») liées par transfer_key, jamais comptées
 * comme gagné ou dépensé.
 */
class MoneyTransaction extends Model
{
    public const SOURCES = [
        'manual' => 'Saisi',
        'import' => 'Relevé',
        'devis' => 'App de devis',
        'recurring' => 'Dépense fixe',
        'loan' => 'Prêt',
    ];

    protected $fillable = [
        'account_id', 'occurred_on', 'amount', 'kind', 'category_id', 'label', 'notes',
        'transfer_key', 'source', 'source_ref', 'import_hash', 'recurring_id',
        'scope', 'claim', 'claim_settled_on', 'claim_key',
    ];

    /** Notes de frais : dépense pro payée avec un compte perso. */
    public const CLAIMS = [
        'a_rembourser' => 'À se faire rembourser',
        'rembourse' => 'Remboursée',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'amount' => 'integer', 'claim_settled_on' => 'date'];
    }

    protected static function booted(): void
    {
        // Mouvement supprimé : ses justificatifs quittent aussi le disque.
        static::deleting(fn (MoneyTransaction $t) => MoneyAttachment::purgeFor([$t->id]));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MoneyCategory::class, 'category_id');
    }

    /** Étiquettes de chantier ou de projet. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MoneyTag::class, 'money_tag_transaction', 'transaction_id', 'tag_id');
    }

    /** Justificatifs (photo du ticket, facture). */
    public function attachments(): HasMany
    {
        return $this->hasMany(MoneyAttachment::class, 'transaction_id');
    }

    /**
     * Mouvements perso, pro, ou tous (« all ») : d'après le compte, sauf vue forcée
     * (une note de frais payée avec le compte perso compte en pro).
     */
    public function scopeInScope(Builder $query, string $scope): Builder
    {
        return $scope === 'all'
            ? $query
            : $query->where(fn (Builder $q) => $q->where('money_transactions.scope', $scope)
                ->orWhere(fn (Builder $w) => $w->whereNull('money_transactions.scope')
                    ->whereIn('money_transactions.account_id', MoneyAccount::query()->where('scope', $scope)->select('id'))));
    }

    public function isClaim(): bool
    {
        return $this->claim !== null;
    }

    /** Perso ou pro : la vue forcée, sinon celle du compte. */
    public function effectiveScope(): ?string
    {
        return $this->scope ?? $this->account?->scope;
    }

    /** Entrées et sorties réelles (les virements entre comptes sont exclus). */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('kind', '!=', 'transfer');
    }

    public function scopeBetweenDates(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to);
    }

    public function isTransfer(): bool
    {
        return $this->kind === 'transfer';
    }

    /** Type selon le signe du montant. */
    public static function kindFor(int $amount): string
    {
        return $amount >= 0 ? 'income' : 'expense';
    }
}
