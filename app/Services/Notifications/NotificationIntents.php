<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\DispatchIncidentNotifications;
use App\Models\Incident;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After-commit notification intent helper.
 *
 * Payload carries incident_id/event_kind/actor only. Never inside a
 * DB transaction; always via afterCommit. Failures reported, never thrown.
 */
final class NotificationIntents
{
    public static function enqueue(int $incidentId, string $eventKind, ?int $actorId = null): void
    {
        try {
            // afterCommit runs now when no transaction open, deferred to
            // commit otherwise. Never inside the transaction body.
            DB::afterCommit(function () use ($incidentId, $eventKind, $actorId): void {
                try {
                    DispatchIncidentNotifications::dispatch($incidentId, $eventKind, $actorId);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Snapshot open incidents for a website keyed by type.
     *
     * @return array<string, array{id: int, severity: string, status: string}>
     */
    public static function snapshotOpen(int $websiteId): array
    {
        $out = [];

        $open = Incident::query()
            ->where('website_id', $websiteId)
            ->whereIn('status', ['DETECTED', 'ACKNOWLEDGED'])
            ->get();

        foreach ($open as $incident) {
            /** @var Incident $incident */
            $out[(string) $incident->type] = [
                'id' => (int) $incident->id,
                'severity' => (string) $incident->severity,
                'status' => (string) $incident->status,
            ];
        }

        return $out;
    }

    /**
     * Diff before/after snapshots to derive opened/escalated/resolved-auto.
     *
     * @param  array<string, array{id: int, severity: string, status: string}>  $before
     */
    public static function enqueueFromDiff(int $websiteId, array $before, ?int $actorId = null): void
    {
        try {
            $after = self::snapshotOpen($websiteId);
            $beforeById = [];
            foreach ($before as $snapshot) {
                $beforeById[$snapshot['id']] = $snapshot;
            }

            // Resolved-auto: was open, now gone from open set.
            foreach ($before as $type => $snapshot) {
                if (! isset($after[$type]) || $after[$type]['id'] !== $snapshot['id']) {
                    /** @var Incident|null $resolved */
                    $resolved = Incident::query()->find($snapshot['id']);
                    if ($resolved !== null && $resolved->status === 'RESOLVED') {
                        self::enqueue($resolved->id, NotificationDispatcher::EVENT_RESOLVED, $actorId);
                    }
                }
            }

            // Opened + escalated from the after set.
            foreach ($after as $type => $current) {
                if (! isset($before[$type]) || $before[$type]['id'] !== $current['id']) {
                    // New incident id for this type, or first open: opened.
                    if (! isset($beforeById[$current['id']])) {
                        self::enqueue($current['id'], NotificationDispatcher::EVENT_OPENED, $actorId);
                    }

                    continue;
                }

                if (self::rank($current['severity']) > self::rank($before[$type]['severity'])) {
                    self::enqueue($current['id'], NotificationDispatcher::EVENT_ESCALATED, $actorId);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function rank(string $severity): int
    {
        return ['INFO' => 0, 'WARNING' => 1, 'CRITICAL' => 2][$severity] ?? 0;
    }
}
