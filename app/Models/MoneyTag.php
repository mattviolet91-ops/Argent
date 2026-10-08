<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Étiquette de chantier ou de projet (« Dupont », « Salle de bain ») posée sur des mouvements. */
class MoneyTag extends Model
{
    public const COLORS = ['#2563EB', '#0EA5E9', '#16A34A', '#CA8A04', '#EA580C', '#DC2626', '#9333EA', '#DB2777', '#475569'];

    protected $fillable = ['name', 'color', 'budget', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return ['budget' => 'integer', 'archived_at' => 'datetime'];
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(MoneyTransaction::class, 'money_tag_transaction', 'tag_id', 'transaction_id');
    }

    /**
     * « Dupont, salle de bain » → étiquettes (créées au besoin, sans doublon de majuscules).
     *
     * @return list<int>
     */
    public static function idsFromInput(?string $input): array
    {
        $ids = [];
        foreach (preg_split('/[,;#\n]+/', (string) $input) ?: [] as $name) {
            $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 0, 60);
            if ($name === '') {
                continue;
            }
            $tag = self::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? self::query()->create(['name' => $name, 'color' => self::COLORS[self::query()->count() % count(self::COLORS)]]);
            if ($tag->archived_at) {
                $tag->update(['archived_at' => null]);
            }
            $ids[] = $tag->id;
        }

        return array_values(array_unique($ids));
    }
}
