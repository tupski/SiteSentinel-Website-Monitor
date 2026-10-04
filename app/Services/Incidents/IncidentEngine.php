<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Check;
use App\Models\Incident;
use App\Models\Website;
use App\Services\Notifications\AdminNotificationService;
use App\Services\StatusPage\StatusPageCache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Incident engine (PLAN.md Phase 6, ARCHITECTURE.md §7).
 *
 * Consumes one persisted check (with its detection result already stored on
 * the row) and reconciles the incident ledger:
 *
 *  - availability threshold cross (consecutive failures >= config) creates or
 *    escalates the open availability incident (FR-53, AC-6-01);
 *  - security score crossing WARNING/CRITICAL creates or escalates the open
 *    security incident (FR-54); INFO never opens an incident (PRD §11.3);
 *  - repeated detections of an open condition dedupe into the existing open
 *    incident and never create duplicates (FR-60, AC-13);
 *  - recovery below the incident threshold auto-resolves only after
 *    `recovery_consecutive_checks` sustained healthy checks (FR-59, AC-11).
 *
 * Notification dispatch is Phase 7 and intentionally absent here (FR-64).
 */
final class IncidentEngine
{
    /** Availability incident severity while the failure persists (PRD §11.3). */
    public const AVAILABILITY_SEVERITY = 'WARNING';

    /**
     * Incidents opened/resolved during the current `processCheck` pass. Collected
     * here and emitted as in-app notifications (ADR-038) AFTER reconciliation, so
     * the additive in-app write can never influence the incident ledger.
     *
     * @var list<Incident>
     */
    private array $openedIncidents = [];

    /** @var list<Incident> */
    private array $resolvedIncidents = [];

    public function __construct(
        private readonly IncidentStateMachine $stateMachine,
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    /**
     * Reconcile incidents for a website after one check persisted.
     *
     * @param  array<string, mixed>  $detection  keys: security_state, score, triggered_rules
     */
    public function processCheck(Website $website, Check $check, array $detection): ?Incident
    {
        $this->openedIncidents = [];
        $this->resolvedIncidents = [];

        $incident = $this->reconcileAvailability($website, $check);

        $security = $this->reconcileSecurity($website, $check, $detection);

        try {
            StatusPageCache::bust();
        } catch (Throwable $e) {
            report($e);
        }

        // In-app notification generation is ADDITIVE (ADR-038): it runs after
        // reconciliation and is fully isolated from the incident ledger and from
        // outbound delivery (never touches the dispatcher/cooldown/logs).
        $this->emitInAppNotifications();

        return $security ?? $incident;
    }

    /**
     * Availability dimension: threshold cross opens/updates, sustained success resolves.
     */
    public function reconcileAvailability(Website $website, Check $check): ?Incident
    {
        $open = $this->findOpen($website, 'availability');

        if ($check->availability_state === 'DOWN') {
            $failureThreshold = max(1, (int) config('sentinel.monitoring.consecutive_failures_threshold', 2));

            if ($website->consecutive_failures < $failureThreshold) {
                // Single transient failure: never an incident by default (FR-53).
                return $open;
            }

            $criticalAfter = max(
                $failureThreshold,
                (int) config('sentinel.incidents.availability_critical_after_failures', 6),
            );
            $severity = $website->consecutive_failures >= $criticalAfter ? 'CRITICAL' : self::AVAILABILITY_SEVERITY;

            return $this->upsertIncident(
                $website,
                type: 'availability',
                dedupeKey: $this->availabilityDedupeKey($website),
                severity: $severity,
                category: 'availability',
                score: 0,
                triggeredRules: null,
                message: sprintf(
                    'Website is DOWN (consecutive failures: %d, last error: %s).',
                    $website->consecutive_failures,
                    (string) ($check->error_type ?? 'unknown'),
                ),
                technicalMetadata: [
                    'failure_classification' => $check->error_type,
                    'consecutive_failures' => $website->consecutive_failures,
                    'http_status' => $check->http_status,
                ],
                existing: $open,
            );
        }

        // UP: auto-resolve the open availability incident after sustained recovery.
        return $this->autoResolveOnRecovery($open, $website, (int) ($check->score ?? 0));
    }

    /**
     * Security dimension: score crossing WARNING opens/updates, sustained OK/under
     * threshold auto-resolves. INFO never opens an incident (PRD §11.3).
     *
     * @param  array<string, mixed>  $detection
     */
    public function reconcileSecurity(Website $website, Check $check, array $detection): ?Incident
    {
        $open = $this->findOpen($website, 'security');

        $score = max(0, (int) ($detection['score'] ?? 0));
        $securityState = (string) ($detection['security_state'] ?? 'OK');

        $severity = $this->severityFor($securityState);

        if ($severity === null) {
            // OK or INFO: below the incident threshold.
            return $this->autoResolveOnRecovery($open, $website, $score);
        }

        return $this->upsertIncident(
            $website,
            type: 'security',
            dedupeKey: $this->securityDedupeKey($website),
            severity: $severity,
            category: 'security',
            score: $score,
            triggeredRules: is_array($detection['triggered_rules'] ?? null) ? $detection['triggered_rules'] : null,
            message: sprintf(
                'Security score %d reached %s on the latest check.',
                $score,
                $severity,
            ),
            technicalMetadata: [
                'security_state' => $securityState,
                'score' => $score,
            ],
            existing: $open,
        );
    }

    /*
    |----------------------------------------------------------------------
    | Internals
    |----------------------------------------------------------------------
    */

    private function findOpen(Website $website, string $type): ?Incident
    {
        /** @var Incident|null $open */
        $open = Incident::query()
            ->where('website_id', $website->id)
            ->where('type', $type)
            ->whereIn('status', [IncidentStateMachine::DETECTED, IncidentStateMachine::ACKNOWLEDGED])
            ->orderByDesc('id')
            ->first();

        return $open;
    }

    /**
     * Create or merge an incident candidate (ARCHITECTURE.md §7 flowchart).
     *
     * @param  array<string, mixed>|null  $triggeredRules
     * @param  array<string, mixed>|null  $technicalMetadata
     */
    private function upsertIncident(
        Website $website,
        string $type,
        string $dedupeKey,
        string $severity,
        string $category,
        int $score,
        ?array $triggeredRules,
        string $message,
        ?array $technicalMetadata,
        ?Incident $existing,
    ): Incident {
        return DB::transaction(function () use ($website, $type, $dedupeKey, $severity, $category, $score, $triggeredRules, $message, $technicalMetadata, $existing): Incident {
            // Re-check inside the transaction: two workers may both pass the
            // pre-transaction `findOpen` and both must not create a row (FR-60).
            $incident = $existing ?? $this->findOpen($website, $type);

            if ($incident === null) {
                /** @var Incident $incident */
                $incident = Incident::query()->create([
                    'website_id' => $website->id,
                    'type' => $type,
                    'category' => $category,
                    'severity' => $severity,
                    'status' => IncidentStateMachine::DETECTED,
                    'score' => $score,
                    'triggered_rules' => $triggeredRules,
                    'message' => $message,
                    'technical_metadata' => $technicalMetadata,
                    'dedupe_key' => $dedupeKey,
                    'detected_at' => now(),
                ]);

                $this->stateMachine->recordEvent(
                    $incident,
                    'created',
                    null,
                    IncidentStateMachine::DETECTED,
                    null,
                    $message,
                    $technicalMetadata,
                );

                // Remember for the additive in-app notification pass (ADR-038).
                $this->openedIncidents[] = $incident;

                return $incident;
            }

            // Merge into the open incident (FR-60). Severity escalates in place
            // and never downgrades (PRD §11.3). Escalation never resolves.
            $escalated = self::maxSeverity($severity, $incident->severity);

            $incident->score = max($incident->score, $score);

            if ($triggeredRules !== null) {
                $incident->triggered_rules = $triggeredRules;
            }

            $incident->technical_metadata = $technicalMetadata;
            $incident->message = $message;

            $severityChanged = $escalated !== $incident->severity;
            $incident->severity = $escalated;
            $incident->save();

            $this->stateMachine->recordEvent(
                $incident,
                $severityChanged ? 'escalated' : 'evidence_appended',
                null,
                null,
                null,
                $message,
                $technicalMetadata,
            );

            return $incident;
        });
    }

    /**
     * Auto-resolve an open incident after sustained recovery (FR-59, AC-11).
     */
    private function autoResolveOnRecovery(?Incident $open, Website $website, int $score): ?Incident
    {
        if ($open === null) {
            return null;
        }

        $required = max(1, (int) config('sentinel.incidents.recovery_consecutive_checks', 2));

        if ($website->consecutive_successes < $required) {
            // Not yet sustained: record recovery progress on the timeline only.
            $this->stateMachine->recordEvent(
                $open,
                'recovery_progress',
                null,
                null,
                null,
                sprintf('Recovery observed (%d/%d consecutive healthy checks).', $website->consecutive_successes, $required),
                ['consecutive_successes' => $website->consecutive_successes, 'required' => $required],
            );

            return $open;
        }

        $this->stateMachine->resolveAutomatically(
            $open,
            sprintf(
                'Auto-resolved after %d consecutive healthy checks (sustained recovery, FR-59).',
                $website->consecutive_successes,
            ),
        );

        // Resolution audit fields set by the state machine; mode stamped there.
        $open->refresh();

        // Remember for the additive in-app notification pass (ADR-038).
        $this->resolvedIncidents[] = $open;

        return $open;
    }

    /**
     * Emit in-app notifications for the incidents opened/resolved in this pass.
     *
     * Best-effort and additive (ADR-038): failures are reported and never
     * propagate, and nothing here touches outbound delivery.
     */
    private function emitInAppNotifications(): void
    {
        try {
            foreach ($this->openedIncidents as $incident) {
                $this->adminNotifications->recordIncidentTransition($incident, 'opened');
            }

            foreach ($this->resolvedIncidents as $incident) {
                $this->adminNotifications->recordIncidentTransition($incident, 'resolved');
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->openedIncidents = [];
            $this->resolvedIncidents = [];
        }
    }

    private function availabilityDedupeKey(Website $website): string
    {
        return 'availability:website:'.$website->id;
    }

    private function securityDedupeKey(Website $website): string
    {
        return 'security:website:'.$website->id;
    }

    private function severityFor(string $securityState): ?string
    {
        // Detection states map onto incident severities (PRD §11.3).
        // SUSPECT ~ WARNING band, INCIDENT ~ CRITICAL band; OK/INFO never open.
        return match ($securityState) {
            'SUSPECT' => 'WARNING',
            'INCIDENT' => 'CRITICAL',
            default => null,
        };
    }

    public static function maxSeverity(string $a, string $b): string
    {
        $rankA = IncidentStateMachine::SEVERITY_RANK[$a] ?? 0;
        $rankB = IncidentStateMachine::SEVERITY_RANK[$b] ?? 0;

        return $rankA >= $rankB ? $a : $b;
    }
}
