<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Notifications sur le téléphone (Web Push) : fonctionne quand l'application
 * est installée sur l'écran d'accueil (iPhone : iOS 16.4 ou plus récent).
 */
class PushService
{
    /** @var list<string> raisons des derniers échecs (affichées lors du test) */
    public array $lastErrors = [];

    public function __construct(private readonly Settings $settings) {}

    /** Clé publique VAPID (créée au premier besoin, la clé privée est chiffrée). */
    public function publicKey(): string
    {
        return $this->keys()['publicKey'];
    }

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     */
    public function subscribe(int $userId, array $subscription, ?string $device): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $subscription['endpoint'])],
            [
                'user_id' => $userId,
                'endpoint' => $subscription['endpoint'],
                'public_key' => $subscription['keys']['p256dh'],
                'auth_token' => $subscription['keys']['auth'],
                'device' => $device ? mb_substr($device, 0, 160) : null,
            ],
        );
    }

    public function unsubscribe(string $endpoint): void
    {
        PushSubscription::query()->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /**
     * Envoie une notification aux téléphones abonnés. Avec $userId : aux appareils de ce compte.
     * Retourne le nombre d'envois réussis.
     */
    public function send(string $title, string $body, ?string $url = null, ?int $userId = null): int
    {
        $this->lastErrors = [];
        $subscriptions = PushSubscription::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        // Sans les extensions GMP / BCMath (possible chez l'hébergeur), la bibliothèque
        // émet un simple avertissement de performance : on l'ignore.
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (str_contains($message, 'GMP or BCMath')) {
                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });

        try {
            $keys = $this->keys();
            $push = new WebPush(['VAPID' => [
                'subject' => 'mailto:'.(User::query()->value('email') ?: 'contact@example.com'),
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ]], ['TTL' => 86400, 'urgency' => 'high'], new Client(['timeout' => 20]));

            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url ?? url('/')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($subscriptions as $subscription) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
            }

            $sent = 0;
            foreach ($push->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    // Appareil désinstallé ou autorisation retirée : abonnement supprimé.
                    $this->unsubscribe($report->getEndpoint());
                    $this->lastErrors[] = 'Abonnement expiré (réactivez les notifications sur ce téléphone).';
                } else {
                    $body = $report->getResponse()?->getBody()?->__toString();
                    $reason = trim($report->getReason().($body ? ' — '.mb_substr($body, 0, 200) : ''));
                    Log::warning('Notification non délivrée', ['reason' => $reason]);
                    $this->lastErrors[] = $reason;
                }
            }

            return $sent;
        } catch (Throwable $e) {
            Log::warning('Échec des notifications', ['error' => $e->getMessage()]);
            $this->lastErrors[] = $e->getMessage();

            return 0;
        } finally {
            restore_error_handler();
        }
    }

    /** @return array{publicKey: string, privateKey: string} */
    private function keys(): array
    {
        $public = $this->settings->get('push.public_key');
        $private = $this->settings->get('push.private_key');

        if ($public && $private) {
            try {
                return ['publicKey' => $public, 'privateKey' => Crypt::decryptString($private)];
            } catch (Throwable) {
                // Clé illisible (APP_KEY changée) : on en recrée une paire.
            }
        }

        $keys = VAPID::createVapidKeys();
        $this->settings->set([
            'push.public_key' => $keys['publicKey'],
            'push.private_key' => Crypt::encryptString($keys['privateKey']),
        ]);
        PushSubscription::query()->delete();

        return $keys;
    }
}
