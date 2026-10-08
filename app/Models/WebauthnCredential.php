<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Appareil autorisé à ouvrir l'app avec Face ID / empreinte. */
class WebauthnCredential extends Model
{
    protected $fillable = ['user_id', 'credential_id', 'public_key', 'sign_count', 'device', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
