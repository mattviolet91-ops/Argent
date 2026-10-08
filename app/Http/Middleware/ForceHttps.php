<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** En ligne, tout passe en https : rien (mot de passe, code, montants) ne circule en clair. */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }
        URL::forceScheme('https');
        if (! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
