<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Notification intent job (NOTIFICATIONS.md §4.1 step 1).
 *
 * Payload carries incident_id/event_kind/actor only. Runs on the
 * `notifications` queue and fans out via the dispatcher. Enqueued
 * after-commit only, never inside a DB transaction.
 */
final class DispatchIncidentNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $incidentId,
        public string $eventKind,
        public ?int $actorId = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        try {
            $dispatcher->dispatch($this->incidentId, $this->eventKind, $this->actorId);
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
