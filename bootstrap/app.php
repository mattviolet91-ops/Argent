<?php

use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Widgets iPhone : sans session ni cookie (une clé par widget).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ForceHttps::class);
        $middleware->append(SecurityHeaders::class);
        // Seule l'adresse officielle (APP_URL) est acceptée dans l'en-tête Host.
        $middleware->trustHosts();
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Jamais gardés pour réafficher un formulaire après une erreur.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'code', 'code_confirmation', 'current_code', 'widget_code', 'pdf_password']);
    })->create();
