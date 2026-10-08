<?php

namespace App\Http\Middleware;

use App\Services\MoneyLockService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App Argent : le propriétaire seul (les autres comptes reçoivent une erreur 403),
 * code Argent obligatoire, et pages jamais gardées en cache (ni navigateur ni téléphone).
 * Paramètre « lock » : pages du verrou (création du code, déverrouillage, code oublié).
 */
class MoneyGate
{
    public function __construct(private readonly MoneyLockService $lock) {}

    public function handle(Request $request, Closure $next, string $mode = 'open'): Response
    {
        if (! $this->lock->canSee($request->user())) {
            abort(403, 'Cet espace est privé.');
        }

        if ($mode === 'open') {
            if (! $this->lock->isConfigured()) {
                return $this->private(redirect()->route('setup'));
            }
            if (! $this->lock->isUnlocked($request)) {
                if ($request->isMethod('GET')) {
                    $request->session()->put('argent.intended', $request->fullUrl());
                }

                return $this->private(redirect()->route('unlock'));
            }
            $this->lock->unlock($request);
        }

        return $this->private($next($request));
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
