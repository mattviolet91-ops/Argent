<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Verrou de l'app Argent : un seul propriétaire (le compte qui a créé le code),
 * un code à part du mot de passe, demandé à chaque ouverture et après quelques
 * minutes sans activité. Après 5 codes faux : bloqué 15 minutes et alerte sur le téléphone.
 */
class MoneyLockService
{
    private const SESSION_KEY = 'argent.unlocked_until';

    private const SESSION_VERSION = 'argent.code_version';

    public const MAX_ATTEMPTS = 5;

    public const BLOCK_MINUTES = 15;

    /** Au-delà de ce nombre de codes faux en 24 h : tous les appareils sont déconnectés (mot de passe exigé). */
    public const MAX_DAILY = 10;

    /** Délai de verrouillage automatique, en minutes. */
    public const DELAYS = [5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 heure'];

    public function __construct(private readonly Settings $settings) {}

    public function ownerId(): ?int
    {
        $id = $this->settings->get('argent.owner_id');

        return $id ? (int) $id : null;
    }

    public function isConfigured(): bool
    {
        return $this->ownerId() !== null && (string) $this->settings->get('argent.code_hash', '') !== '';
    }

    /** Le compte peut-il ouvrir l'app ? Seulement le propriétaire, une fois le code créé. */
    public function canSee(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $owner = $this->ownerId();

        return $owner === null || $owner === $user->id;
    }

    public function setCode(User $user, string $code): void
    {
        $this->settings->set(['argent.owner_id' => $user->id, 'argent.code_hash' => Hash::make($code)]);
    }

    public function checkCode(string $code): bool
    {
        $hash = (string) $this->settings->get('argent.code_hash', '');

        return $hash !== '' && Hash::check($code, $hash);
    }

    public function lockMinutes(): int
    {
        $minutes = (int) $this->settings->get('argent.lock_minutes', 15);

        return array_key_exists($minutes, self::DELAYS) ? $minutes : 15;
    }

    /** Reverrouiller quand l'app passe en arrière-plan plus de 30 secondes. */
    public function locksOnLeave(): bool
    {
        return (bool) $this->settings->get('argent.lock_on_leave', true);
    }

    public function isUnlocked(Request $request): bool
    {
        $session = $request->session();
        if ($session->get(self::SESSION_VERSION) !== $this->codeVersion()) {
            return false;
        }

        return (int) $session->get(self::SESSION_KEY, 0) > now()->getTimestamp();
    }

    /** Chaque page ouverte repousse le verrouillage automatique. */
    public function unlock(Request $request, bool $fresh = false): void
    {
        if ($fresh) {
            $request->session()->regenerate();
        }
        $request->session()->put([
            self::SESSION_KEY => now()->addMinutes($this->lockMinutes())->getTimestamp(),
            self::SESSION_VERSION => $this->codeVersion(),
        ]);
    }

    public function lock(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, self::SESSION_VERSION]);
    }

    /** Secondes avant de pouvoir réessayer (0 : pas bloqué). */
    public function blockedFor(): int
    {
        return RateLimiter::tooManyAttempts($this->limiterKey(), self::MAX_ATTEMPTS)
            ? RateLimiter::availableIn($this->limiterKey())
            : 0;
    }

    /** Code faux : compte l'essai ; au 5e, blocage et alerte. Retourne les essais restants. */
    public function failed(Request $request): int
    {
        RateLimiter::hit($this->limiterKey(), self::BLOCK_MINUTES * 60);
        $left = max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($this->limiterKey()));

        RateLimiter::hit($this->limiterKey().':jour', 24 * 3600);
        if (RateLimiter::attempts($this->limiterKey().':jour') >= self::MAX_DAILY) {
            $this->logoutEverywhere($request);

            return 0;
        }

        if ($left === 0) {
            ActivityLogger::log('argent.blocked', 'App Argent bloqué '.self::BLOCK_MINUTES.' minutes après '.self::MAX_ATTEMPTS.' codes faux');
            app(PushService::class)->send(
                'App Argent bloqué',
                self::MAX_ATTEMPTS.' codes faux viennent d\'être tapés. Si ce n\'était pas vous, changez votre mot de passe.',
                route('settings'),
                $this->ownerId(),
            );
        }

        return $left;
    }

    public function succeeded(): void
    {
        RateLimiter::clear($this->limiterKey());
        RateLimiter::clear($this->limiterKey().':jour');
    }

    /**
     * Trop de codes faux sur une journée : quelqu'un essaie peut-être de deviner le code.
     * Toutes les sessions et tous les « rester connecté » sont coupés : il faudra le mot de passe.
     */
    private function logoutEverywhere(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }
        ActivityLogger::log('argent.logout_all', 'Déconnexion de tous les appareils après '.self::MAX_DAILY.' codes faux en 24 h');
        app(PushService::class)->send(
            'Argent : appareils déconnectés',
            self::MAX_DAILY.' codes faux en 24 h : tous les appareils ont été déconnectés. Si ce n\'était pas vous, changez votre mot de passe.',
            route('login'),
            $user->id,
        );
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        RateLimiter::clear($this->limiterKey().':jour');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function limiterKey(): string
    {
        return 'argent-code:'.($this->ownerId() ?? 0);
    }

    /** Change quand le code change : les sessions ouvertes avec l'ancien code se reverrouillent. */
    public function codeVersion(): string
    {
        return substr(hash('sha256', (string) $this->settings->get('argent.code_hash', '')), 0, 16);
    }
}
