<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Models\PushSubscription;
use App\Services\Notifications\MessageRedactor;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Browser Push provider (NOTIFICATIONS.md §7.3, ADR-032).
 *
 * Implements the SAME contract as EmailProvider/TelegramProvider and is
 * registered in NotificationProviderRegistry. The incident engine and the
 * dispatcher contain no push-specific branching (ADR-010, ADR-032).
 *
 * VAPID private key comes from config only and is NEVER logged. Subscription
 * material (endpoint/p256dh/auth) is read from the encrypted model and is
 * NEVER logged or placed in the payload. Payloads pass through
 * MessageRedactor before send (NOTIFICATIONS.md §7.3).
 *
 * The transport is injectable so tests can exercise the contract without a
 * real network call: when no transport is supplied, a real Minishlink
 * WebPush client is built lazily at send time.
 */
final class WebPushProvider implements NotificationProvider
{
    /**
     * Max push payload size accepted by the Web Push encryption layer (octets).
     */
    private const MAX_PAYLOAD_LENGTH = 4078;

    /**
     * @param  array<string, mixed>  $channelConfig
     * @param  callable(array<string, mixed>, string, array<string, mixed>): array{ok: bool, code?: string, expired?: bool, message?: string, id?: string}|null  $transport
     */
    public function __construct(
        private readonly array $channelConfig = [],
        private readonly ?string $channelSecret = null,
        private readonly mixed $transport = null,
    ) {}

    public function supports(string $eventKind): bool
    {
        return true;
    }

    public function validateConfig(array $config, ?string $secret): bool|array
    {
        $errors = [];

        if ((string) config('sentinel.push.vapid_public_key', '') === '') {
            $errors[] = 'VAPID_PUBLIC_KEY is not configured.';
        }

        if ((string) config('sentinel.push.vapid_private_key', '') === '') {
            $errors[] = 'VAPID_PRIVATE_KEY is not configured.';
        }

        return $errors === [] ? true : $errors;
    }

    public function send(NotificationPayload $payload): DeliveryResult
    {
        $started = (int) (microtime(true) * 1000);
        $config = $this->channelConfig;

        if (! $this->vapidConfigured()) {
            return new DeliveryResult(ok: false, error_code: 'config_error', error_message: 'VAPID keys are not configured', retryable: false, latency_ms: $this->latency($started));
        }

        $subscriptions = $this->resolveSubscriptions($config);

        if ($subscriptions === []) {
            return new DeliveryResult(ok: false, error_code: 'no_subscriptions', error_message: 'no enabled push subscriptions', retryable: false, latency_ms: $this->latency($started));
        }

        $body = $this->payloadBody($payload);

        $sent = 0;
        $expired = false;

        foreach ($subscriptions as $subscription) {
            try {
                $outcome = $this->deliver($subscription, $body);
            } catch (Throwable $e) {
                report($e);

                return new DeliveryResult(ok: false, error_code: 'push_exception', error_message: MessageRedactor::redact($e->getMessage()), retryable: true, latency_ms: $this->latency($started));
            }

            if ($outcome['ok']) {
                $sent++;

                continue;
            }

            if (($outcome['expired'] ?? false) === true) {
                $expired = true;
                $this->disableSubscription($subscription);

                continue;
            }

            // Any non-expired failure is surfaced so the dispatcher owns
            // retry classification and logging.
            return new DeliveryResult(
                ok: false,
                error_code: (string) ($outcome['code'] ?? 'push_failed'),
                error_message: MessageRedactor::redact((string) ($outcome['message'] ?? 'push delivery failed')),
                retryable: (bool) ($outcome['retryable'] ?? true),
                latency_ms: $this->latency($started),
            );
        }

        if ($sent === 0) {
            return new DeliveryResult(ok: false, error_code: $expired ? 'subscription_expired' : 'push_failed', error_message: $expired ? 'all push subscriptions expired' : 'push delivery failed', retryable: false, latency_ms: $this->latency($started));
        }

        return new DeliveryResult(ok: true, provider_message_id: 'push:'.$sent, latency_ms: $this->latency($started));
    }

    private function vapidConfigured(): bool
    {
        return (string) config('sentinel.push.vapid_public_key', '') !== ''
            && (string) config('sentinel.push.vapid_private_key', '') !== '';
    }

    /**
     * Payload carries the same redacted content as the other providers: a
     * title, the summary, and the admin deep link only — never a secret
     * (NOTIFICATIONS.md §7.3, FR-73, SECURITY.md §4).
     *
     * @return array<string, mixed>
     */
    private function payloadArray(NotificationPayload $payload, int $summaryLimit = 300): array
    {
        $summary = MessageRedactor::redact($payload->summary) ?? '';

        return [
            'title' => mb_substr($payload->title, 0, 255),
            'body' => mb_substr($summary, 0, $summaryLimit),
            'severity' => $payload->severity,
            'event_kind' => $payload->event_kind,
            'incident_id' => $payload->incident_id,
            'url' => $payload->admin_url,
            'tag' => $payload->dedupe_key,
        ];
    }

    private function payloadBody(NotificationPayload $payload): string
    {
        $json = $this->encode($this->payloadArray($payload));

        if (strlen($json) <= self::MAX_PAYLOAD_LENGTH) {
            return $json;
        }

        return $this->encode($this->payloadArray($payload, 120));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, PushSubscription>
     */
    private function resolveSubscriptions(array $config): array
    {
        $query = PushSubscription::query()->where('enabled', true);

        $websiteId = $config['website_id'] ?? null;
        if ($websiteId !== null) {
            $query->where(function ($q) use ($websiteId): void {
                $q->whereNull('website_id')->orWhere('website_id', (int) $websiteId);
            });
        }

        $userId = $config['user_id'] ?? null;
        if ($userId !== null) {
            $query->where('user_id', (int) $userId);
        }

        /** @var array<int, PushSubscription> $rows */
        $rows = $query->get()->all();

        return $rows;
    }

    /**
     * @return array{ok: bool, code?: string, expired?: bool, message?: string, retryable?: bool, id?: string}
     */
    private function deliver(PushSubscription $subscription, string $body): array
    {
        $endpoint = (string) $subscription->endpoint;
        $p256dh = (string) ($subscription->p256dh ?? '');
        $auth = (string) ($subscription->auth ?? '');

        if (is_callable($this->transport)) {
            /** @var array{ok: bool, code?: string, expired?: bool, message?: string, retryable?: bool, id?: string} $result */
            $result = ($this->transport)(
                ['endpoint' => $endpoint, 'keys' => ['p256dh' => $p256dh, 'auth' => $auth]],
                $body,
                [
                    'ttl' => (int) config('sentinel.push.ttl_seconds', 3600),
                    'vapid' => [
                        'subject' => (string) config('sentinel.push.vapid_subject', ''),
                        'publicKey' => (string) config('sentinel.push.vapid_public_key', ''),
                        'privateKey' => (string) config('sentinel.push.vapid_private_key', ''),
                    ],
                ],
            );

            return $result;
        }

        return $this->deliverViaTransport($endpoint, $p256dh, $auth, $body);
    }

    /**
     * @return array{ok: bool, code?: string, expired?: bool, message?: string, retryable?: bool, id?: string}
     */
    private function deliverViaTransport(string $endpoint, string $p256dh, string $auth, string $body): array
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('sentinel.push.vapid_subject', ''),
                'publicKey' => (string) config('sentinel.push.vapid_public_key', ''),
                'privateKey' => (string) config('sentinel.push.vapid_private_key', ''),
            ],
        ]);

        $subscription = Subscription::create([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
        ]);

        $report = $webPush->sendOneNotification(
            $subscription,
            $body,
            ['TTL' => (int) config('sentinel.push.ttl_seconds', 3600)],
        );

        if ($report->isSuccess()) {
            return ['ok' => true, 'code' => 'sent'];
        }

        $status = $report->getResponse()?->getStatusCode();

        return [
            'ok' => false,
            'code' => $report->isSubscriptionExpired() ? 'subscription_expired' : 'push_http_'.(string) ($status ?? 'error'),
            'expired' => $report->isSubscriptionExpired(),
            'message' => (string) $report->getReason(),
            'retryable' => ! ($status !== null && $status >= 400 && $status < 500 && $status !== 429),
        ];
    }

    private function disableSubscription(PushSubscription $subscription): void
    {
        try {
            $subscription->enabled = false;
            $subscription->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function latency(int $started): int
    {
        return max(0, (int) (microtime(true) * 1000) - $started);
    }
}
