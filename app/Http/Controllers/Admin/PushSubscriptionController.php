<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeletePushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Browser Push subscription lifecycle + test-send (NOTIFICATIONS.md §7.3,
 * ADR-032).
 *
 * All routes are behind auth + admin + session timeouts + CSRF (routes/web.php).
 * Subscription material is never echoed back and never logged; the VAPID
 * private key never leaves config (SECURITY.md §4).
 */
final class PushSubscriptionController extends Controller
{
    public function __construct(
        private readonly NotificationProviderRegistry $registry,
    ) {}

    /**
     * Upsert a subscription for the current user, keyed by the endpoint hash.
     */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $endpoint = (string) $validated['endpoint'];

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'endpoint' => $endpoint,
                'p256dh' => (string) $validated['keys']['p256dh'],
                'auth' => (string) $validated['keys']['auth'],
                'user_agent' => mb_substr((string) ($validated['user_agent'] ?? $request->userAgent() ?? ''), 0, 512) ?: null,
                'enabled' => true,
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Disable (and drop) the subscription for the current user's endpoint.
     */
    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        $endpoint = (string) $request->validated()['endpoint'];

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Send a clearly-labelled test push through the provider contract. Writes a
     * `channel.test` notification_logs row scoped to the test. Rate-limited +
     * CSRF-protected; no secret is exposed (NOTIFICATIONS.md §7.3, §14.1).
     */
    public function test(): RedirectResponse
    {
        $channel = NotificationChannel::query()
            ->where('type', 'browser_push')
            ->where('enabled', true)
            ->orderBy('id')
            ->first();

        if ($channel === null) {
            return back()->with('status', __('No enabled Browser Push channel is configured.'));
        }

        $payload = $this->buildTestPayload((int) $channel->id);
        $result = $this->deliver($payload);

        NotificationLog::create([
            'incident_id' => null,
            'channel_id' => $channel->id,
            'status' => $result->ok ? 'sent' : 'failed',
            'attempt' => 1,
            'provider_message_id' => $result->provider_message_id,
            'error' => $result->ok ? null : MessageRedactor::redact(
                ($result->error_code !== null ? '['.$result->error_code.'] ' : '').($result->error_message ?? 'test-send failed')
            ),
            'dedupe_key' => $payload->dedupe_key,
            'sent_at' => $result->ok ? now() : null,
        ]);

        return back()->with('status', $result->ok
            ? __('Test push sent.')
            : __('Test push failed: '.($result->error_code ?? 'delivery failed').'.'));
    }

    private function deliver(NotificationPayload $payload): DeliveryResult
    {
        try {
            $provider = $this->registry->resolve('browser_push');
        } catch (\Throwable $e) {
            return new DeliveryResult(ok: false, error_code: 'unknown_provider', error_message: 'browser_push provider unavailable', retryable: false);
        }

        return $provider->send($payload);
    }

    /**
     * The `channel.test` payload used by the test path (NOTIFICATIONS.md §3,
     * §14.1). Carries no secrets, evidence, or subscription material.
     */
    private function buildTestPayload(int $channelId): NotificationPayload
    {
        return new NotificationPayload(
            incident_id: 0,
            website_id: 0,
            event_kind: NotificationDispatcher::EVENT_TEST,
            severity: 'INFO',
            title: '[SiteSentinel] Test notification',
            summary: 'This is a test push from the SiteSentinel admin area.',
            detected_at: new DateTimeImmutable('now'),
            admin_url: rtrim((string) config('app.url', 'http://localhost'), '/').'/admin/notifications',
            dedupe_key: NotificationDispatcher::EVENT_TEST.':channel:'.$channelId.':'.time(),
        );
    }
}
