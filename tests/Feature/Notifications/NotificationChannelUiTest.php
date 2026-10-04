<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notifications\Channels\EmailProvider;
use App\Services\Notifications\Channels\TelegramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Plan S3 — notification settings simplification + test-before-save.
 *
 * Covers the page rename ("Channels" -> "Notification"), type-aware
 * conditional fields / server validation, and the unsaved-config test action
 * that routes through the provider registry without persisting a channel.
 * No live network: HTTP is faked or the provider is spied.
 */
final class NotificationChannelUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        RateLimiter::clear('notifications.test:127.0.0.1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'is_active' => true]);
    }

    /**
     * A spy provider that records whether it was invoked and can script a
     * result. The constructor is parameterless so the container can
     * instantiate it (optionally with `channelConfig`/`channelSecret`) exactly
     * like the real provider — proving the controller goes through the
     * provider path rather than a bespoke send.
     */
    private function spyProvider(bool $ok = true, array $errors = []): NotificationProvider
    {
        return new class implements NotificationProvider
        {
            public int $sendCount = 0;

            public bool $ok = true;

            /** @var list<string> */
            public array $errors = [];

            public ?NotificationPayload $payload = null;

            /** @param array<string, mixed> $channelConfig */
            public function __construct(
                public readonly array $channelConfig = [],
                public readonly ?string $channelSecret = null,
            ) {}

            public function send(NotificationPayload $payload): DeliveryResult
            {
                $this->sendCount++;
                $this->payload = $payload;

                return $this->ok
                    ? new DeliveryResult(ok: true, provider_message_id: 'spy-1', latency_ms: 1)
                    : new DeliveryResult(ok: false, error_code: 'spy_failed', error_message: 'spy failure', retryable: false);
            }

            public function supports(string $eventKind): bool
            {
                return true;
            }

            /** @param array<string, mixed> $config */
            public function validateConfig(array $config, ?string $secret): bool|array
            {
                return $this->errors === [] ? true : $this->errors;
            }
        };
    }

    /**
     * Bind a spy in a resolvable way: copy scripted flags onto the spy and bind
     * it, and mirror the binding onto the anonymous subclass name the
     * controller instantiates so config/secret construction resolves too.
     *
     * @param  class-string<NotificationProvider>  $forClass
     */
    private function bindSpy(string $forClass, NotificationProvider $spy, bool $ok = true, array $errors = []): NotificationProvider
    {
        $spy->ok = $ok;
        $spy->errors = $errors;

        $this->app->instance($forClass, $spy);

        return $spy;
    }

    /** @return array<string, mixed> */
    private function telegramPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'telegram',
            'name' => 'Ops telegram',
            'enabled' => '1',
            'telegram_chat_id' => '123456',
            'secret_ref' => 'fake-bot-token-secret-value',
        ], $overrides);
    }

    public function test_page_heading_reads_notification_not_channels(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.notifications.index'));
        $response->assertOk();
        $response->assertSee('>Notification</h1>', false);
        $response->assertDontSee('Channels');
    }

    public function test_create_form_reveals_type_conditional_fields(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.notifications.create'));
        $response->assertOk();

        // Both type field-sets exist but are gated on the selected `type` via
        // Alpine, and every type-specific input is disabled when inactive so an
        // irrelevant field is never submitted (Plan S3).
        $response->assertSee('x-show="type === \'email\'"', false);
        $response->assertSee('x-show="type === \'telegram\'"', false);
        $response->assertSee('x-bind:disabled="type !== \'email\'"', false);
        $response->assertSee('x-bind:disabled="type !== \'telegram\'"', false);
        $response->assertSee('name="type"', false);
    }

    public function test_store_rejects_email_fields_for_telegram_type(): void
    {
        $this->actingAs($this->admin());

        $response = $this->from(route('admin.notifications.create'))
            ->post(route('admin.notifications.store'), $this->telegramPayload([
                'email_host' => 'smtp.example.com',
                'email_from_address' => 'sentinel@example.com',
            ]));

        $response->assertSessionHasErrors(['email_host', 'email_from_address']);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_store_rejects_telegram_fields_for_email_type(): void
    {
        $this->actingAs($this->admin());

        $response = $this->from(route('admin.notifications.create'))
            ->post(route('admin.notifications.store'), [
                'type' => 'email',
                'name' => 'Ops email',
                'email_recipients_text' => 'ops@example.com',
                'email_host' => 'smtp.example.com',
                'email_port' => '587',
                'email_from_address' => 'sentinel@example.com',
                'telegram_chat_id' => '123456',
                'secret_ref' => 'secret',
            ]);

        $response->assertSessionHasErrors(['telegram_chat_id']);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_store_enforces_type_required_fields(): void
    {
        $this->actingAs($this->admin());

        $response = $this->from(route('admin.notifications.create'))
            ->post(route('admin.notifications.store'), [
                'type' => 'telegram',
                'name' => 'Missing chat',
                'secret_ref' => 'token',
            ]);

        $response->assertSessionHasErrors(['telegram_chat_id']);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_store_accepts_valid_telegram_config(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.notifications.store'), $this->telegramPayload())
            ->assertRedirect(route('admin.notifications.index'));

        $this->assertSame(1, NotificationChannel::query()->count());
    }

    public function test_test_action_calls_provider_via_registry_without_persisting(): void
    {
        $this->actingAs($this->admin());

        $spy = $this->bindSpy(TelegramProvider::class, $this->spyProvider());

        $response = $this->postJson(route('admin.notifications.test'), $this->telegramPayload());

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, $spy->sendCount, 'test must dispatch through the resolved provider.');
        $this->assertNotNull($spy->payload);
        $this->assertSame('channel.test', $spy->payload->event_kind);

        // No channel side effect: the test proves the config, it does not save it.
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_test_action_email_uses_email_provider(): void
    {
        $this->actingAs($this->admin());

        $spy = $this->bindSpy(EmailProvider::class, $this->spyProvider());

        $response = $this->postJson(route('admin.notifications.test'), [
            'type' => 'email',
            'name' => 'Ops email',
            'email_recipients_text' => 'ops@example.com',
            'email_host' => 'smtp.example.com',
            'email_port' => '587',
            'email_from_address' => 'sentinel@example.com',
            'secret_ref' => 'smtp-password-secret',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, $spy->sendCount);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_test_action_response_contains_no_secret(): void
    {
        $this->actingAs($this->admin());

        $secret = 'super-secret-bot-token-1234567890';
        $response = $this->postJson(route('admin.notifications.test'), $this->telegramPayload([
            'secret_ref' => $secret,
        ]));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($secret, $body);

        // Log rows must not carry the secret either.
        $logError = NotificationLog::query()->pluck('error')->implode(' | ');
        $this->assertStringNotContainsString($secret, $logError);
    }

    public function test_test_action_failure_reports_safe_message(): void
    {
        $this->actingAs($this->admin());

        $spy = $this->bindSpy(TelegramProvider::class, $this->spyProvider(), ok: false);

        $response = $this->postJson(route('admin.notifications.test'), $this->telegramPayload());

        $response->assertOk()->assertJson(['ok' => false, 'error_code' => 'spy_failed']);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_test_action_rejects_invalid_config_via_provider(): void
    {
        $this->actingAs($this->admin());

        $spy = $this->bindSpy(TelegramProvider::class, $this->spyProvider(), errors: ['telegram config missing: chat_id.']);

        $response = $this->postJson(route('admin.notifications.test'), $this->telegramPayload([
            'telegram_chat_id' => '',
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, $spy->sendCount, 'an invalid config must never reach the transport.');
    }

    public function test_test_action_rejects_irrelevant_fields_by_type(): void
    {
        $this->actingAs($this->admin());

        $spy = $this->bindSpy(TelegramProvider::class, $this->spyProvider());

        $response = $this->postJson(route('admin.notifications.test'), $this->telegramPayload([
            'email_host' => 'smtp.example.com',
        ]));

        $response->assertStatus(422);
        $this->assertSame(0, $spy->sendCount);
    }

    public function test_test_action_is_rate_limited(): void
    {
        $this->actingAs($this->admin());

        $statuses = [];
        for ($i = 0; $i < 35; $i++) {
            $statuses[] = $this->postJson(route('admin.notifications.test'), $this->telegramPayload())->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'test must throttle under burst (throttle:30,1).');
    }

    public function test_test_action_requires_authentication(): void
    {
        $this->post(route('admin.notifications.test'), $this->telegramPayload())
            ->assertRedirect(route('login'));
    }

    public function test_test_action_requires_admin(): void
    {
        $this->actingAs($this->viewer());

        $this->post(route('admin.notifications.test'), $this->telegramPayload())
            ->assertForbidden();
    }
}
