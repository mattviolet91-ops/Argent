<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Abonnement de l'appareil aux notifications et notification de test. */
class PushController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $this->push->subscribe((int) auth()->id(), $data, $request->userAgent());

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $this->push->unsubscribe($data['endpoint']);

        return response()->json(['ok' => true]);
    }

    public function test(): JsonResponse
    {
        $sent = $this->push->send('Notifications activées', 'Les notifications arrivent bien sur ce téléphone.', route('dashboard'), auth()->id());

        return response()->json([
            'sent' => $sent,
            'devices' => PushSubscription::query()->where('user_id', auth()->id())->count(),
            'errors' => array_values(array_unique($this->push->lastErrors)),
        ]);
    }
}
