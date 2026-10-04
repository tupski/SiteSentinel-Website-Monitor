<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\Channels\WebPushProvider;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Web Push provider contract + payload redaction (NOTIFICATIONS.md §7.3,
 * ADR-032, SECURITY.md §4).
 *
 * The transport is faked via an injected callable — NO real network is made.
 * The suite proves: registry resolution, ok/failure classification, expired
 * subscription disablement, VAPID config gating, and that no subscription
 * secret or VAPID private key ever appears in the payload.
 */
final class WebPushProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sentinel.push.vapid_public_key', 'BPublicKeyExample');
        config()->set('sentinel.push.vapid_private_key', 'PRIVATE-KEY-MUST-NEVER-LEAK');
        config()->set('sentinel.push.vapid_subject', 'mailto:admin@example.test');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(array $overrides = []): PushSubscription
    {
        $endpoint = (string) ($overrides['endpoint'] ?? 'https://push.example.test/'.uniqid());

        return PushSubscription::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'p256dh-client-key-material',
            'auth' => 'auth-client-secret-material',
            'user_agent' => 'phpunit',
            'enabled' => true,
        ], $overrides));
    }

    private function payload(string $summary = 'Content served does not match this website baseline.'): NotificationPayload
    {
        return new NotificationPayload(
            incident_id: 42,
            website_id: 7,
            event_kind: 'incident.opened',
            severity: 'CRITICAL',
            title: '[SiteSentinel] CRITICAL — Example: security incident opened',
            summary: $summary,
            detected_at: new \DateTimeImmutable('2026-09-30 12:00:00'),
            admin_url: 'https://monitor.example.test/admin/incidents/42',
            dedupe_key: '42:incident.opened:9',
        );
    }

    public function test_registry_resolves_browser_push_to_web_push_provider(): void
    {
        $provider = app(NotificationProviderRegistry::class)->resolve('browser_push');

        $this->assertInstanceOf(WebPushProvider::class, $provider);
        $this->assertInstanceOf(NotificationProvider::class, $provider);
        $this->assertTrue(NotificationProviderRegistry::known('browser_push'));
    }

    public function test_unsupported_when_vapid_not_configured(): void
    {
        config()->set('sentinel.push.vapid_public_key', '');

        $provider = new WebPushProvider(['user_id' => 1], null, fn () => ['ok' => true]);

        $result = $provider->send($this->payload());

        $this->assertFalse($result->ok);
        $this->assertSame('config_error', $result->error_code);
        $this->assertFalse($result->retryable);
    }

    public function test_validate_config_reports_missing_vapid_keys(): void
    {
        config()->set('sentinel.push.vapid_private_key', '');

        $provider = new WebPushProvider;
        $errors = $provider->validateConfig([], null);

        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
    }

    public function test_send_returns_ok_and_never_leaks_secrets_in_payload(): void
    {
        $subscription = $this->subscription(['user_id' => 1]);
        /** @var array{sub: array<string, mixed>, body: string} $captured */
        $captured = ['sub' => [], 'body' => ''];

        $provider = new WebPushProvider(
            ['user_id' => 1],
            null,
            function (array $sub, string $body) use (&$captured): array {
                $captured = ['sub' => $sub, 'body' => $body];

                return ['ok' => true, 'code' => 'sent', 'id' => 'msg-1'];
            },
        );

        $result = $provider->send($this->payload());

        $this->assertTrue($result->ok);
        $this->assertNotNull($captured);

        // No subscription material or VAPID private key in the payload body.
        $this->assertStringNotContainsString('p256dh-client-key-material', $captured['body']);
        $this->assertStringNotContainsString('auth-client-secret-material', $captured['body']);
        $this->assertStringNotContainsString('PRIVATE-KEY-MUST-NEVER-LEAK', $captured['body']);
        $this->assertStringNotContainsString($subscription->endpoint, $captured['body']);

        // The endpoint/key material is passed to the transport, not the payload.
        $this->assertSame($subscription->endpoint, $captured['sub']['endpoint']);
    }

    public function test_expired_subscription_is_disabled_and_reported(): void
    {
        $subscription = $this->subscription(['user_id' => 1]);

        $provider = new WebPushProvider(
            ['user_id' => 1],
            null,
            fn (): array => ['ok' => false, 'code' => 'subscription_expired', 'expired' => true, 'message' => 'gone'],
        );

        $result = $provider->send($this->payload());

        $this->assertFalse($result->ok);
        $this->assertFalse($subscription->fresh()->enabled);
    }

    public function test_failed_delivery_surfaces_redacted_error(): void
    {
        $this->subscription(['user_id' => 1]);

        $provider = new WebPushProvider(
            ['user_id' => 1],
            null,
            fn (): array => ['ok' => false, 'code' => 'push_http_500', 'message' => 'Authorization: Bearer supersecrettoken', 'retryable' => true],
        );

        $result = $provider->send($this->payload());

        $this->assertFalse($result->ok);
        $this->assertTrue($result->retryable);
        $this->assertStringNotContainsString('supersecrettoken', (string) $result->error_message);
        $this->assertStringContainsString('[REDACTED]', (string) $result->error_message);
    }

    public function test_send_without_subscriptions_fails_closed(): void
    {
        $provider = new WebPushProvider(['user_id' => 999], null, fn (): array => ['ok' => true]);

        $result = $provider->send($this->payload());

        $this->assertFalse($result->ok);
        $this->assertSame('no_subscriptions', $result->error_code);
        $this->assertFalse($result->retryable);
    }

    public function test_push_subscription_secrets_are_encrypted_at_rest_and_hidden(): void
    {
        $subscription = $this->subscription();
        $rawEndpoint = (string) DB::table('push_subscriptions')
            ->where('id', $subscription->id)
            ->value('endpoint');

        $this->assertStringNotContainsString((string) $subscription->endpoint, $rawEndpoint);

        $array = $subscription->toArray();
        $this->assertArrayNotHasKey('endpoint', $array);
        $this->assertArrayNotHasKey('p256dh', $array);
        $this->assertArrayNotHasKey('auth', $array);

        $this->assertSame($subscription->endpoint, $subscription->fresh()->endpoint);
    }
}
