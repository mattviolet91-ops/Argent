<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** « Ce mot dans le libellé bancaire → cette catégorie » (appris à l'import d'un relevé). */
class MoneyRule extends Model
{
    protected $fillable = ['keyword', 'category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MoneyCategory::class, 'category_id');
    }
}
