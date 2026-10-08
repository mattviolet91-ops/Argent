<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\Request;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;
use Throwable;

/**
 * Face ID / empreinte (WebAuthn) : le téléphone garde une clé secrète protégée par
 * le visage ou le doigt ; le serveur ne garde que la clé publique et vérifie la
 * signature à chaque ouverture. Aucune donnée biométrique ne quitte le téléphone.
 */
class FaceIdService
{
    private const SESSION_KEY = 'faceid.challenge';

    public function enabledFor(User $user): bool
    {
        return WebauthnCredential::query()->where('user_id', $user->id)->exists();
    }

    /** Options pour enregistrer cet appareil (navigator.credentials.create). */
    public function registrationOptions(User $user, Request $request): array
    {
        $webAuthn = $this->server($request);
        $exclude = WebauthnCredential::query()->where('user_id', $user->id)->pluck('credential_id')
            ->map(fn ($id) => self::decode($id))->all();
        $args = $webAuthn->getCreateArgs((string) $user->id, $user->email, $user->name, 120, false, 'required', false, $exclude);
        $request->session()->put(self::SESSION_KEY, base64_encode($webAuthn->getChallenge()->getBinaryString()));

        return json_decode(json_encode($args), true);
    }

    /** @param  array<string, mixed>  $payload */
    public function register(User $user, Request $request, array $payload): WebauthnCredential
    {
        $challenge = $this->pullChallenge($request);
        try {
            $data = $this->server($request)->processCreate(
                self::decode((string) ($payload['clientDataJSON'] ?? '')),
                self::decode((string) ($payload['attestationObject'] ?? '')),
                $challenge,
                true, true, false,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Enregistrement refusé ('.$e->getMessage().').');
        }

        return WebauthnCredential::query()->create([
            'user_id' => $user->id,
            'credential_id' => self::encode($data->credentialId),
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) $data->signatureCounter,
            'device' => self::device((string) $request->userAgent()),
        ]);
    }

    /** Options pour ouvrir l'app (navigator.credentials.get). */
    public function unlockOptions(User $user, Request $request): array
    {
        $webAuthn = $this->server($request);
        $ids = WebauthnCredential::query()->where('user_id', $user->id)->pluck('credential_id')->map(fn ($id) => self::decode($id))->all();
        $args = $webAuthn->getGetArgs($ids, 120, true, true, true, true, true, 'required');
        $request->session()->put(self::SESSION_KEY, base64_encode($webAuthn->getChallenge()->getBinaryString()));

        return json_decode(json_encode($args), true);
    }

    /** @param  array<string, mixed>  $payload */
    public function verify(User $user, Request $request, array $payload): bool
    {
        $challenge = $this->pullChallenge($request);
        $credential = WebauthnCredential::query()->where('user_id', $user->id)
            ->where('credential_id', self::encode(self::decode((string) ($payload['id'] ?? ''))))->first();
        if (! $credential || $challenge === null) {
            return false;
        }

        try {
            $webAuthn = $this->server($request);
            $webAuthn->processGet(
                self::decode((string) ($payload['clientDataJSON'] ?? '')),
                self::decode((string) ($payload['authenticatorData'] ?? '')),
                self::decode((string) ($payload['signature'] ?? '')),
                $credential->public_key,
                $challenge,
                $credential->sign_count,
                true,
            );
        } catch (Throwable) {
            return false;
        }

        $credential->forceFill(['sign_count' => (int) ($webAuthn->getSignatureCounter() ?? $credential->sign_count), 'last_used_at' => now()])->save();

        return true;
    }

    private function server(Request $request): WebAuthn
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost();

        return new WebAuthn('Argent', $host, ['none'], true);
    }

    private function pullChallenge(Request $request): ?ByteBuffer
    {
        $stored = (string) $request->session()->pull(self::SESSION_KEY, '');

        return $stored === '' ? null : new ByteBuffer((string) base64_decode($stored, true));
    }

    /** base64url (ce qu'envoie le navigateur) → binaire. */
    public static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** « iPhone · Safari », « Mac · Chrome »… */
    public static function device(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            default => 'Appareil',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'CriOS'), str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };

        return $os.($browser ? ' · '.$browser : '');
    }
}
