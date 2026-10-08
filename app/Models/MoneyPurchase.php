<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Achat gardé avec sa facture et sa date de fin de garantie. */
class MoneyPurchase extends Model
{
    /** Durées de garantie proposées (mois). */
    public const WARRANTIES = [
        '' => 'Pas de garantie',
        '6' => '6 mois',
        '12' => '1 an',
        '24' => '2 ans (garantie légale)',
        '36' => '3 ans',
        '60' => '5 ans',
        '120' => '10 ans (décennale)',
        'date' => 'Jusqu\'à une date…',
    ];

    /** Prévenu ce nombre de jours avant la fin de la garantie. */
    public const NOTICE_DAYS = 30;

    protected $fillable = [
        'name', 'shop', 'purchased_on', 'amount', 'warranty_until', 'transaction_id',
        'file_path', 'file_name', 'file_mime', 'file_size', 'notes',
    ];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'warranty_until' => 'date', 'amount' => 'integer', 'file_size' => 'integer'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'transaction_id');
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->file_mime, 'image/');
    }

    /** Jours restants de garantie (négatif : terminée), null sans garantie. */
    public function daysLeft(?Carbon $today = null): ?int
    {
        return $this->warranty_until ? (int) ($today ?? today())->diffInDays($this->warranty_until, false) : null;
    }

    /** covered (sous garantie), soon (se termine bientôt), ended (terminée) ou none. */
    public function status(?Carbon $today = null): string
    {
        $days = $this->daysLeft($today);

        return match (true) {
            $days === null => 'none',
            $days < 0 => 'ended',
            $days <= self::NOTICE_DAYS => 'soon',
            default => 'covered',
        };
    }
}
