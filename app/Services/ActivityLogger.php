<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

/** Journal de sécurité (visible dans Réglages) : jamais de montant dedans. */
class ActivityLogger
{
    public static function log(string $action, string $description): ActivityLog
    {
        $request = request();

        return ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => Str::limit($description, 250),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? Str::limit($request->userAgent(), 250) : null,
        ]);
    }
}
