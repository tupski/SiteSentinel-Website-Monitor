<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\DB;

/**
 * Channel circuit-breaker (NOTIFICATIONS.md §12.4).
 *
 * Disables channel after sustained failure + audit row. Monitoring
 * continues; only alerting degrades (NFR-10).
 */
final class CircuitBreaker
{
    private const PERMANENT_STREAK_LIMIT = 5;

    private const EXHAUSTION_WINDOW_LIMIT = 10;

    public function recordOutcome(NotificationChannel $channel, bool $ok, bool $retryable): void
    {
        if ($ok) {
            return;
        }

        if (! $retryable) {
            $streak = $this->consecutivePermanentFailures((int) $channel->id);

            if ($streak >= self::PERMANENT_STREAK_LIMIT) {
                $this->openCircuit($channel, "permanent failure streak {$streak}");
            }

            return;
        }

        $exhaustions = $this->recentExhaustions((int) $channel->id);

        if ($exhaustions >= self::EXHAUSTION_WINDOW_LIMIT) {
            $this->openCircuit($channel, "retry exhaustion {$exhaustions}/1h");
        }
    }

    private function consecutivePermanentFailures(int $channelId): int
    {
        /** @var array<int, string> $statuses */
        $statuses = NotificationLog::query()
            ->where('channel_id', $channelId)
            ->orderByDesc('id')
            ->limit(self::PERMANENT_STREAK_LIMIT)
            ->pluck('status')
            ->all();

        $streak = 0;
        foreach ($statuses as $status) {
            if ($status !== 'failed') {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    private function recentExhaustions(int $channelId): int
    {
        return NotificationLog::query()
            ->where('channel_id', $channelId)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    private function openCircuit(NotificationChannel $channel, string $reason): void
    {
        $channel->enabled = false;
        $channel->save();

        try {
            DB::table('audit_logs')->insert([
                'event' => 'notification.channel_disabled',
                'subject_type' => NotificationChannel::class,
                'subject_id' => $channel->id,
                'ip_address' => null,
                'user_agent' => null,
                'metadata' => json_encode(['reason' => $reason, 'channel' => $channel->name]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
