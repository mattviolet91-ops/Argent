<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Lien avec l'app de devis (Matt's Couverture) : lecture seule des paiements reçus,
 * des frais et de ce qui reste à encaisser, avec la clé « App Argent » créée dans
 * l'app de devis (Réglages → Accès Claude). La clé est gardée chiffrée.
 */
class DevisClient
{
    public function __construct(private readonly Settings $settings) {}

    public function url(): string
    {
        return rtrim((string) $this->settings->get('devis.url', ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->token() !== null;
    }

    public function token(): ?string
    {
        $encrypted = (string) $this->settings->get('devis.token', '');
        if ($encrypted === '') {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function configure(string $url, ?string $token): void
    {
        $values = ['devis.url' => rtrim($url, '/')];
        if ($token !== null && $token !== '') {
            $values['devis.token'] = Crypt::encryptString($token);
        }
        $this->settings->set($values);
    }

    /**
     * @return array{payments: list<array<string, mixed>>, expenses: list<array<string, mixed>>, summary: array<string, int>}
     *
     * @throws RuntimeException message en français, à afficher tel quel
     */
    public function fetch(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Le lien avec l\'app de devis n\'est pas réglé (Réglages → Lien avec l\'app de devis).');
        }

        try {
            $response = Http::withToken((string) $this->token())->acceptJson()->timeout(30)->retry(2, 1000, throw: false)
                ->get($this->url().'/api/v1/argent');
        } catch (Throwable) {
            throw new RuntimeException('L\'app de devis ne répond pas ('.$this->url().'). Vérifiez l\'adresse ou réessayez plus tard.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Clé refusée par l\'app de devis : créez une nouvelle « clé de l\'app Argent » (Réglages → Accès Claude) et collez-la ici.');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! isset($data['payments'], $data['expenses'], $data['summary'])) {
            throw new RuntimeException('Réponse inattendue de l\'app de devis (erreur '.$response->status().'). Est-elle à jour ?');
        }

        return $data;
    }
}
