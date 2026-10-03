<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationPayload;
use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationCooldown;
use App\Models\NotificationLog;
use App\Models\Website;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provider-independent fan-out (NOTIFICATIONS.md §4, ADR-010).
 *
 * Ordered gates per channel: severity -> cooldown/suppression -> dedupe.
 * Fans out one SendNotification job per surviving channel on the
 * `notifications` queue. Never touches incident state. Per-channel
 * isolation: one channel failing never blocks another.
 */
final class NotificationDispatcher
{
    public const EVENT_OPENED = 'incident.opened';

    public const EVENT_ESCALATED = 'incident.escalated';

    public const EVENT_ACKNOWLEDGED = 'incident.acknowledged';

    public const EVENT_RESOLVED = 'incident.resolved';

    public const EVENT_REMINDER = 'incident.reminder';

    public const EVENT_TEST = 'channel.test';

    /** @var array<string, int> */
    private const SEVERITY_RANK = ['INFO' => 0, 'WARNING' => 1, 'CRITICAL' => 2];

    /**
     * Dispatch an incident event. Payload carries incident_id/event_kind/actor
     * only; everything else is re-resolved fresh.
     */
    public function dispatch(int $incidentId, string $eventKind, ?int $actorId = null): void
    {
        /** @var Incident|null $incident */
        $incident = Incident::query()->find($incidentId);

        if ($incident === null) {
            return;
        }

        /** @var Website|null $website */
        $website = Website::query()->find($incident->website_id);

        if ($website === null) {
            return;
        }

        foreach ($this->resolveChannels($website) as $channel) {
            try {
                $this->dispatchToChannel($incident, $website, $channel, $eventKind, $actorId);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function resolveChannels(Website $website): array
    {
        $scopedIds = DB::table('website_notification_channel')
            ->where('website_id', $website->id)
            ->pluck('channel_id')
            ->all();

        $query = NotificationChannel::query()->where('enabled', true);

        if ($scopedIds !== []) {
            $query->whereIn('id', $scopedIds);
        }

        /** @var array<int, NotificationChannel> $channels */
        $channels = $query->orderBy('id')->get()->all();

        return $channels;
    }

    public static function dedupeKey(int $incidentId, string $eventKind, int $channelId): string
    {
        return $incidentId.':'.$eventKind.':'.$channelId;
    }

    public static function cooldownKey(int $channelId, int $websiteId): string
    {
        return $channelId.':'.$websiteId;
    }

    public static function adminUrl(int $incidentId): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/').'/admin/incidents/'.$incidentId;
    }

    /**
     * @return array<string, mixed>
     */
    public static function templateVars(Incident $incident, Website $website, string $eventKind): array
    {
        return [
            'website' => ['name' => $website->name, 'url' => $website->url],
            'severity' => $incident->severity,
            'incident_type' => $incident->type,
            'score' => $incident->score,
            'summary' => (string) ($incident->message ?? ''),
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'incident_id' => $incident->id,
            'current_status' => $incident->status,
            'admin_url' => self::adminUrl($incident->id),
            'event_kind' => $eventKind,
        ];
    }

    public static function buildPayload(Incident $incident, Website $website, string $eventKind, int $channelId): NotificationPayload
    {
        $title = sprintf(
            '[SiteSentinel] %s — %s: %s %s',
            $incident->severity,
            $website->name,
            $incident->type,
            str_replace('incident.', '', $eventKind)
        );

        return new NotificationPayload(
            incident_id: $incident->id,
            website_id: $website->id,
            event_kind: $eventKind,
            severity: $incident->severity,
            title: mb_substr($title, 0, 255),
            summary: mb_substr((string) ($incident->message ?? ''), 0, 2000),
            detected_at: new DateTimeImmutable($incident->detected_at?->toIso8601String() ?? 'now'),
            admin_url: self::adminUrl($incident->id),
            dedupe_key: self::dedupeKey($incident->id, $eventKind, $channelId),
            template_vars: self::templateVars($incident, $website, $eventKind),
        );
    }

    private function dispatchToChannel(
        Incident $incident,
        Website $website,
        NotificationChannel $channel,
        string $eventKind,
        ?int $actorId,
    ): void {
        $dedupeKey = self::dedupeKey($incident->id, $eventKind, (int) $channel->id);

        // Gate 1: severity. INFO never notifies; per-channel minimum applies.
        if (! $this->passesSeverityGate($incident->severity, $channel)) {
            $this->logSuppressed($incident->id, (int) $channel->id, $dedupeKey, 'severity gate: '.$incident->severity.' below minimum');

            return;
        }

        // Gate 2: cooldown/suppression with bypass for escalation-higher + recovery.
        if ($this->isCooldownActive((int) $channel->id, $website->id) && ! $this->bypassesCooldown($eventKind)) {
            $this->touchSuppressedCount((int) $channel->id, $website->id);
            $this->logSuppressed($incident->id, (int) $channel->id, $dedupeKey, 'cooldown active');

            return;
        }

        // Gate 3: dedupe identity — one sent row per incident+event+channel.
        $alreadySent = NotificationLog::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', 'sent')
            ->exists();

        if ($alreadySent) {
            $this->logSuppressed($incident->id, (int) $channel->id, $dedupeKey, 'duplicate dedupe_key');

            return;
        }

        // Unknown provider type fails closed (NOTIFICATIONS §2.5).
        if (! NotificationProviderRegistry::known((string) $channel->type)) {
            NotificationLog::create([
                'incident_id' => $incident->id,
                'channel_id' => $channel->id,
                'status' => 'failed',
                'attempt' => 0,
                'error' => MessageRedactor::redact('unknown channel type: '.$channel->type),
                'dedupe_key' => $dedupeKey,
            ]);

            return;
        }

        // Open/refresh the cooldown window for non-bypass events.
        if (! $this->bypassesCooldown($eventKind)) {
            $this->openCooldownWindow($incident->id, (int) $channel->id, $website->id, $eventKind);
        }

        // Constructor pins queue to `notifications`; plain dispatch keeps this
        // worker-safe (already outside any transaction here).
        SendNotification::dispatch($incident->id, (int) $channel->id, $eventKind, $actorId);
    }

    private function passesSeverityGate(string $severity, NotificationChannel $channel): bool
    {
        if (($severity === 'INFO') || ! isset(self::SEVERITY_RANK[$severity])) {
            return false;
        }

        $config = is_array($channel->config) ? $channel->config : [];
        $minimum = strtoupper((string) ($config['min_severity'] ?? 'WARNING'));

        $required = self::SEVERITY_RANK[$minimum] ?? self::SEVERITY_RANK['WARNING'];

        return (self::SEVERITY_RANK[$severity] ?? 0) >= $required;
    }

    private function bypassesCooldown(string $eventKind): bool
    {
        // Escalation-higher + recovery never suppressed; first open for a new
        // incident also bypasses since it starts its own window.
        return in_array($eventKind, [self::EVENT_ESCALATED, self::EVENT_RESOLVED, self::EVENT_OPENED], true);
    }

    private function isCooldownActive(int $channelId, int $websiteId): bool
    {
        return NotificationCooldown::query()
            ->where('cooldown_key', self::cooldownKey($channelId, $websiteId))
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function openCooldownWindow(int $incidentId, int $channelId, int $websiteId, string $eventKind): void
    {
        $minutes = max(1, (int) config('sentinel.notifications.default_cooldown_minutes', 15));
        $now = now();

        NotificationCooldown::updateOrCreate(
            ['cooldown_key' => self::cooldownKey($channelId, $websiteId)],
            [
                'incident_id' => $incidentId,
                'channel_id' => $channelId,
                'event_kind' => $eventKind,
                'window_started_at' => $now,
                'expires_at' => $now->copy()->addMinutes($minutes),
                'suppressed_count' => 0,
            ]
        );
    }

    private function touchSuppressedCount(int $channelId, int $websiteId): void
    {
        NotificationCooldown::query()
            ->where('cooldown_key', self::cooldownKey($channelId, $websiteId))
            ->increment('suppressed_count');
    }

    private function logSuppressed(int $incidentId, int $channelId, string $dedupeKey, string $reason): void
    {
        NotificationLog::create([
            'incident_id' => $incidentId,
            'channel_id' => $channelId,
            'status' => 'suppressed',
            'attempt' => 0,
            'error' => MessageRedactor::redact($reason),
            'dedupe_key' => $dedupeKey,
        ]);
    }
}
