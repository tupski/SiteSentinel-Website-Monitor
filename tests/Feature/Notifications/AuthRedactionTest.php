<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Auth plus redaction boundary for notification admin (SECURITY.md §3-§4).
 *
 * Role matrix: guest blocked, non-admin blocked, admin allowed, on every
 * notification route and method. Secrets never in responses or logs,
 * error messages scrubbed, test-send throttled. No live creds or network.
 */
final class AuthRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        RateLimiter::clear('test-send:127.0.0.1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer', 'is_active' => true]);
    }

    private function website(): Website
    {
        return Website::create([
            'name' => 'Example',
            'url' => 'https://public.example.test/',
            'scheme' => 'https',
            'host' => 'public.example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);
    }

    private function channel(): NotificationChannel
    {
        return NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Ops tg',
            'enabled' => true,
            'config' => ['chat_id' => '123456', 'min_severity' => 'WARNING'],
            'secret_ref' => 'fake-bot-token-secret-value',
        ]);
    }

    public function test_unauthenticated_blocked_all_notification_routes_and_methods(): void
    {
        $channel = $this->channel();

        $this->get(route('admin.notifications.index'))->assertRedirect(route('login'));
        $this->get(route('admin.notifications.create'))->assertRedirect(route('login'));
        $this->post(route('admin.notifications.store'))->assertRedirect(route('login'));
        $this->get(route('admin.notifications.edit', $channel))->assertRedirect(route('login'));
        $this->put(route('admin.notifications.update', $channel))->assertRedirect(route('login'));
        $this->delete(route('admin.notifications.destroy', $channel))->assertRedirect(route('login'));
        $this->post(route('admin.notifications.test-send', $channel))->assertRedirect(route('login'));
        $this->get(route('admin.notification-logs.index'))->assertRedirect(route('login'));
    }

    public function test_non_admin_blocked_all_notification_routes_and_methods(): void
    {
        $this->actingAs($this->viewer());
        $channel = $this->channel();

        $this->get(route('admin.notifications.index'))->assertForbidden();
        $this->get(route('admin.notifications.create'))->assertForbidden();
        $this->post(route('admin.notifications.store'))->assertForbidden();
        $this->get(route('admin.notifications.edit', $channel))->assertForbidden();
        $this->put(route('admin.notifications.update', $channel))->assertForbidden();
        $this->delete(route('admin.notifications.destroy', $channel))->assertForbidden();
        $this->post(route('admin.notifications.test-send', $channel))->assertForbidden();
        $this->get(route('admin.notification-logs.index'))->assertForbidden();
    }

    public function test_admin_allowed_and_secrets_never_in_responses(): void
    {
        $this->actingAs($this->admin());
        $channel = $this->channel();

        $index = $this->get(route('admin.notifications.index'));
        $index->assertOk();
        $index->assertDontSee('fake-bot-token-secret-value', false);

        $edit = $this->get(route('admin.notifications.edit', $channel));
        $edit->assertOk();
        $edit->assertDontSee('fake-bot-token-secret-value', false);

        $logs = $this->get(route('admin.notification-logs.index'));
        $logs->assertOk();
        $logs->assertDontSee('fake-bot-token-secret-value', false);

        $this->website();
        $form = $this->get(route('admin.notifications.create'));
        $form->assertOk();
        $form->assertDontSee('fake-bot-token-secret-value', false);
    }

    public function test_secrets_never_in_logs(): void
    {
        $this->actingAs($this->admin());
        $channel = $this->channel();

        $this->post(route('admin.notifications.test-send', $channel))->assertRedirect();

        $this->assertGreaterThan(0, NotificationLog::query()->where('channel_id', $channel->id)->count());
        foreach (NotificationLog::query()->where('channel_id', $channel->id)->get() as $log) {
            $this->assertStringNotContainsString('fake-bot-token-secret-value', (string) $log->error);
            $this->assertStringNotContainsString('fake-bot-token-secret-value', (string) $log->provider_message_id);
        }

        foreach (NotificationLog::query()->where('channel_id', $channel->id)->pluck('error')->all() as $error) {
            if ($error === null) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase('bot_token=fake', $error);
            $this->assertStringNotContainsStringIgnoringCase('token=fake', $error);
        }
    }

    public function test_error_messages_scrubbed(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized: bot_token=fake-bot-token-secret-value rejected'], 401),
        ]);

        $this->actingAs($this->admin());
        $channel = $this->channel();

        $response = $this->post(route('admin.notifications.test-send', $channel));
        $response->assertRedirect();
        $response->assertSessionMissing('secret_ref');

        $log = NotificationLog::query()->where('channel_id', $channel->id)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('fake-bot-token-secret-value', (string) $log->error);
    }

    public function test_no_evidence_in_payload_or_logs(): void
    {
        $this->actingAs($this->admin());
        $channel = $this->channel();
        $this->website();

        $this->post(route('admin.notifications.test-send', $channel))->assertRedirect();

        $combined = NotificationLog::query()->where('channel_id', $channel->id)->pluck('error')->implode(' | ');
        $combined .= ' '.$channel->refresh()->name;
        foreach (['RULE-', 'redirect_chain', 'snapshot', 'keyword'] as $frag) {
            $this->assertStringNotContainsString($frag, $combined);
        }

        $response = $this->get(route('admin.notification-logs.index'));
        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('fake-bot-token-secret-value', $body);
    }

    public function test_test_send_throttled(): void
    {
        $this->actingAs($this->admin());
        $channel = $this->channel();

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->post(route('admin.notifications.test-send', $channel))->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'test-send must throttle under burst (throttle:10,1); got: '.implode(',', $statuses));
    }
}
