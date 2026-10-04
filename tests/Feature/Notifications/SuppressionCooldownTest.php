<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationCooldown;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suppression and cooldown gates (NOTIFICATIONS.md §9).
 *
 * Controllable clock via travelTo/travel. Default 15min window.
 * Reminder events open the window; opened/escalated/resolved bypass.
 */
final class SuppressionCooldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
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

    private function incident(Website $website, array $overrides = []): Incident
    {
        return Incident::create(array_merge([
            'website_id' => $website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$website->id,
            'detected_at' => now(),
        ], $overrides));
    }

    private function emailChannel(): NotificationChannel
    {
        return NotificationChannel::create([
            'type' => 'email',
            'name' => 'Ops mail',
            'enabled' => true,
            'config' => [
                'recipients' => ['ops@example.test'],
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'alerts@example.test',
                'from_name' => 'SiteSentinel',
                'min_severity' => 'WARNING',
            ],
            'secret_ref' => 'smtp-secret-value',
        ]);
    }

    public function test_first_allowed(): void
    {
        $channel = $this->emailChannel();
        $incident = $this->incident($this->website());

        app(NotificationDispatcher::class)->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);

        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
    }

    public function test_repeat_inside_15min_suppressed_with_count(): void
    {
        $channel = $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);

        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'suppressed')->count());

        $cooldown = NotificationCooldown::query()
            ->where('cooldown_key', NotificationDispatcher::cooldownKey((int) $channel->id, $incident->website_id))
            ->firstOrFail();
        $this->assertSame(1, $cooldown->suppressed_count);
    }

    /**
     * Cooldown applies unchanged to Browser Push (ADR-032, NOTIFICATIONS §9):
     * a repeat reminder inside the window is suppressed for a `browser_push`
     * channel exactly as for email. No real network is reached — with VAPID
     * unset the provider fails closed, but the cooldown gate still fires.
     */
    public function test_browser_push_respects_cooldown(): void
    {
        config()->set('sentinel.push.vapid_public_key', '');
        config()->set('sentinel.push.vapid_private_key', '');

        $channel = NotificationChannel::create([
            'type' => 'browser_push',
            'name' => 'Browser push',
            'enabled' => true,
            'config' => ['min_severity' => 'WARNING'],
        ]);
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);

        $this->assertSame(1, NotificationLog::query()
            ->where('channel_id', $channel->id)
            ->where('status', 'suppressed')
            ->count());

        $cooldown = NotificationCooldown::query()
            ->where('cooldown_key', NotificationDispatcher::cooldownKey((int) $channel->id, $incident->website_id))
            ->firstOrFail();
        $this->assertSame(1, $cooldown->suppressed_count);
    }

    public function test_escalation_bypasses_cooldown(): void
    {
        $channel = $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);

        $incident->severity = 'CRITICAL';
        $incident->save();
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_ESCALATED);

        $this->assertSame(2, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
    }

    public function test_recovery_bypasses_cooldown(): void
    {
        $channel = $this->emailChannel();
        $incident = $this->incident($this->website());
        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_REMINDER);

        $incident->status = 'RESOLVED';
        $incident->save();
        $dispatcher->dispatch($incident->id, NotificationDispatcher::EVENT_RESOLVED);

        $this->assertSame(2, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
    }

    public function test_new_incident_new_key_not_suppressed(): void
    {
        $channel = $this->emailChannel();
        $website = $this->website();
        $dispatcher = app(NotificationDispatcher::class);

        $first = $this->incident($website);
        $dispatcher->dispatch($first->id, NotificationDispatcher::EVENT_REMINDER);

        $second = $this->incident($website, ['dedupe_key' => 'security:website:'.$website->id.':second']);
        $dispatcher->dispatch($second->id, NotificationDispatcher::EVENT_OPENED);

        // Opened events bypass cooldown; distinct incidents hold distinct
        // dedupe keys, so both deliver.
        $this->assertSame(2, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
    }

    public function test_clock_boundary_exact(): void
    {
        $channel = $this->emailChannel();
        $website = $this->website();
        $dispatcher = app(NotificationDispatcher::class);

        $first = $this->incident($website);
        $dispatcher->dispatch($first->id, NotificationDispatcher::EVENT_REMINDER);

        $cooldown = NotificationCooldown::query()
            ->where('cooldown_key', NotificationDispatcher::cooldownKey((int) $channel->id, $website->id))
            ->firstOrFail();
        $expires = $cooldown->expires_at->copy();

        // Just inside the window still suppresses.
        $this->travelTo($expires->copy()->subSecond());
        $second = $this->incident($website, ['dedupe_key' => 'security:website:'.$website->id.':inside']);
        $dispatcher->dispatch($second->id, NotificationDispatcher::EVENT_REMINDER);
        $this->assertSame(1, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());

        // Just outside the window sends again.
        $this->travelTo($expires->copy()->addSecond());
        $third = $this->incident($website, ['dedupe_key' => 'security:website:'.$website->id.':outside']);
        $dispatcher->dispatch($third->id, NotificationDispatcher::EVENT_REMINDER);
        $this->assertSame(2, NotificationLog::query()->where('channel_id', $channel->id)->where('status', 'sent')->count());
    }
}
