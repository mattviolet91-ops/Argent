<?php

namespace App\Http\Controllers;

use App\Models\WebauthnCredential;
use App\Services\ActivityLogger;
use App\Services\FaceIdService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Réglages → Face ID : autoriser cet appareil, ou retirer un appareil. */
class FaceIdController extends Controller
{
    public function __construct(private readonly FaceIdService $faceId) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->faceId->registrationOptions($request->user(), $request));
    }

    public function store(Request $request, PushService $push): JsonResponse
    {
        try {
            $credential = $this->faceId->register($request->user(), $request, (array) $request->json()->all());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLogger::log('faceid.added', 'Face ID activé sur '.$credential->device);
        $push->send('Face ID activé', 'Un appareil peut maintenant ouvrir Argent avec Face ID : '.$credential->device.'.', route('settings'), $request->user()->id);
        $request->session()->flash('status', 'Face ID activé sur cet appareil. Le code seul ne suffit plus pour ouvrir l\'app.');

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, WebauthnCredential $credential): RedirectResponse
    {
        abort_unless($credential->user_id === $request->user()->id, 404);
        $credential->delete();
        ActivityLogger::log('faceid.removed', 'Face ID retiré de '.$credential->device);

        return redirect()->route('settings')->with('status', 'Appareil retiré.'.($this->faceId->enabledFor($request->user()) ? '' : ' Sans Face ID, le code suffit de nouveau pour ouvrir l\'app.'));
    }
}
