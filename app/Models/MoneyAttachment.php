<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Justificatif (photo du ticket, facture PDF) joint à un mouvement. */
class MoneyAttachment extends Model
{
    public const MAX_PER_TRANSACTION = 5;

    protected $fillable = ['transaction_id', 'path', 'name', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'transaction_id');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /** Fichiers des mouvements supprimés en lot (synchro des devis…) : retirés du disque. */
    public static function purgeFor(iterable $transactionIds): void
    {
        $ids = collect($transactionIds)->all();
        if ($ids === []) {
            return;
        }
        foreach (self::query()->whereIn('transaction_id', $ids)->get() as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->delete();
        }
    }
}
