<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\FaceIdService;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Connexion (email + mot de passe), puis le code Argent est demandé. */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);
        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);
            throw ValidationException::withMessages(['email' => "Trop de tentatives. Réessayez dans {$minutes} minute(s)."]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 15 * 60);
            ActivityLogger::log('auth.failed', 'Connexion refusée ('.Str::limit($credentials['email'], 80).')');
            throw ValidationException::withMessages(['email' => 'Email ou mot de passe incorrect.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $device = FaceIdService::device((string) $request->userAgent());
        ActivityLogger::log('auth.login', 'Connexion ('.$device.')');
        $this->alertIfNewDevice($request, $device);

        return redirect()->intended(route('dashboard'));
    }

    /** Premier passage d'un appareil (hors toute première connexion) : notification sur les téléphones déjà connus. */
    private function alertIfNewDevice(Request $request, string $device): void
    {
        $settings = app(Settings::class);
        $known = (array) $settings->get('auth.devices', []);
        $fingerprint = hash('sha256', (string) $request->userAgent());
        if (in_array($fingerprint, $known, true)) {
            return;
        }
        $settings->set(['auth.devices' => array_slice(array_merge($known, [$fingerprint]), -20)]);
        if ($known !== []) {
            app(PushService::class)->send(
                'Nouvelle connexion à Argent',
                'Connexion depuis un nouvel appareil ('.$device.'). Si ce n\'est pas vous, changez votre mot de passe.',
                route('settings'),
                $request->user()->id,
            );
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        ActivityLogger::log('auth.logout', 'Déconnexion');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Vous êtes déconnecté.');
    }
}
