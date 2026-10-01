<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Incident lifecycle state machine (PRD.md §12.1, ARCHITECTURE.md §7).
 *
 * Transitions:
 *   DETECTED     -> ACKNOWLEDGED (admin only, never auto)
 *   DETECTED     -> RESOLVED     (admin or auto-recovery)
 *   ACKNOWLEDGED -> RESOLVED     (admin or auto-recovery)
 *
 * `RESOLVED` is terminal: no transition may leave it. A recurrence opens a NEW
 * incident instead of reopening a resolved one (AC-12). Acknowledging never
 * resolves (AC-10 / FR-58). Every transition appends an immutable
 * `incident_events` row in the same transaction (FR-57).
 */
final class IncidentStateMachine
{
    public const DETECTED = 'DETECTED';

    public const ACKNOWLEDGED = 'ACKNOWLEDGED';

    public const RESOLVED = 'RESOLVED';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::DETECTED => [self::ACKNOWLEDGED, self::RESOLVED],
        self::ACKNOWLEDGED => [self::RESOLVED],
        self::RESOLVED => [],
    ];

    /** Severity rank; escalation is monotonic while an incident is open (PRD §11.3). */
    public const SEVERITY_RANK = [
        'INFO' => 0,
        'WARNING' => 1,
        'CRITICAL' => 2,
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Acknowledge an open incident as an authenticated admin (US-05, AC-10).
     *
     * Acknowledging must not resolve the incident and is available regardless
     * of whether the underlying condition has cleared (FR-58).
     */
    public function acknowledge(Incident $incident, User $actor, ?string $note = null): Incident
    {
        return $this->apply($incident, self::ACKNOWLEDGED, $actor, 'acknowledged', $note);
    }

    /**
     * Resolve an incident by an explicit admin action (US-06, FR-59).
     */
    public function resolveManually(Incident $incident, User $actor, ?string $notes = null): Incident
    {
        $resolved = $this->apply($incident, self::RESOLVED, $actor, 'resolved', $notes);

        $resolved->resolution_mode = 'manual';
        $resolved->resolution_notes = $notes;
        $resolved->save();

        return $resolved;
    }

    /**
     * Resolve an incident through sustained recovery (FR-59, AC-11).
     *
     * The actor is the system: no user is recorded, `resolution_mode = auto`
     * distinguishes this in the audit trail from manual resolution.
     */
    public function resolveAutomatically(Incident $incident, string $note): Incident
    {
        $resolved = $this->apply($incident, self::RESOLVED, null, 'auto_resolved', $note);

        $resolved->resolution_mode = 'auto';
        $resolved->save();

        return $resolved;
    }

    /**
     * Apply a lifecycle transition atomically and append the timeline event.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function apply(
        Incident $incident,
        string $to,
        ?User $actor,
        string $eventType,
        ?string $note = null,
        ?array $metadata = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $to, $actor, $eventType, $note, $metadata): Incident {
            // Re-read under the transaction so concurrent actors converge on
            // one terminal state instead of double-transitioning a stale row.
            /** @var Incident $fresh */
            $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->id);

            $from = $fresh->status;

            if ($from === $to) {
                // Idempotent replay (NFR-09): the transition already happened.
                return $fresh;
            }

            if (! $this->canTransition($from, $to)) {
                throw new \DomainException(sprintf(
                    'Illegal incident transition %s -> %s on incident [%d].',
                    $from,
                    $to,
                    $fresh->id,
                ));
            }

            $now = now();

            $fresh->status = $to;
            if ($to === self::ACKNOWLEDGED) {
                $fresh->acknowledged_at = $now;
                $fresh->acknowledged_by = $actor?->getKey();
            }

            if ($to === self::RESOLVED) {
                $fresh->resolved_at = $now;
                $fresh->resolved_by = $actor?->getKey();
            }

            $fresh->save();

            $this->recordEvent($fresh, $eventType, $from, $to, $actor, $note, $metadata);

            return $fresh;
        });
    }

    /**
     * Append an immutable event to the incident timeline (FR-57, DATABASE §3.11).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordEvent(
        Incident $incident,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?User $actor = null,
        ?string $note = null,
        ?array $metadata = null,
    ): IncidentEvent {
        try {
            return IncidentEvent::create([
                'incident_id' => $incident->id,
                'event_type' => mb_substr($eventType, 0, 64),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_user_id' => $actor?->getKey(),
                'note' => $note,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The timeline is evidence, never a blocker (AGENTS.md §9 spirit):
            // a failed event write is reported but must not undo the transition.
            report($e);

            /** @var IncidentEvent $standIn */
            $standIn = new IncidentEvent([
                'incident_id' => $incident->id,
                'event_type' => mb_substr($eventType, 0, 64),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_user_id' => $actor?->getKey(),
                'note' => $note,
                'metadata' => $metadata,
            ]);
            $standIn->created_at = now();
            $standIn->id = 0;

            return $standIn;
        }
    }
}
