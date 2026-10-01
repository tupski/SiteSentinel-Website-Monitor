<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Services\Incidents\IncidentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * State machine semantics (PLAN.md Phase 6, PRD.md §12.1).
 *
 * Terminal semantics, illegal transitions and acknowledge-not-resolve
 * (AC-6-02, FR-58, FR-59) are asserted here with direct engine calls.
 */
final class IncidentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private IncidentStateMachine $machine;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = app(IncidentStateMachine::class);

        $this->website = Website::create([
            'name' => 'Example',
            'url' => 'https://example.test/',
            'scheme' => 'https',
            'host' => 'example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);
    }

    private function makeIncident(): Incident
    {
        return Incident::create([
            'website_id' => $this->website->id,
            'type' => 'security',
            'severity' => 'WARNING',
            'status' => 'DETECTED',
            'score' => 9,
            'dedupe_key' => 'security:website:'.$this->website->id,
            'detected_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'supersecretlongpassword',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /** AC-6-02 (AC-10 / FR-58): acknowledge records actor + timestamp, does not resolve. */
    public function test_acknowledge_records_actor_and_does_not_resolve(): void
    {
        $incident = $this->makeIncident();
        $admin = $this->admin();

        $acknowledged = $this->machine->acknowledge($incident, $admin, 'Looking into it.');

        $this->assertSame('ACKNOWLEDGED', $acknowledged->status);
        $this->assertNotNull($acknowledged->acknowledged_at);
        $this->assertSame($admin->id, $acknowledged->acknowledged_by);
        $this->assertNull($acknowledged->resolved_at, 'Acknowledging must never resolve (FR-58).');

        // Audit trail: transition event with the actor recorded (FR-57).
        $event = $acknowledged->events()->where('event_type', 'acknowledged')->first();
        $this->assertNotNull($event);
        $this->assertSame('DETECTED', $event->from_status);
        $this->assertSame('ACKNOWLEDGED', $event->to_status);
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    /** AC-6-04: RESOLVED is terminal — no transition may leave it. */
    public function test_resolved_is_terminal(): void
    {
        $incident = $this->makeIncident();
        $admin = $this->admin();

        $resolved = $this->machine->resolveManually($incident, $admin, 'fixed');

        $this->assertSame('RESOLVED', $resolved->status);
        $this->assertSame('manual', $resolved->resolution_mode);
        $this->assertSame($admin->id, $resolved->resolved_by);

        $this->assertFalse($this->machine->canTransition('RESOLVED', 'DETECTED'));
        $this->assertFalse($this->machine->canTransition('RESOLVED', 'ACKNOWLEDGED'));

        $this->expectException(\DomainException::class);
        $this->machine->acknowledge($resolved, $admin);
    }

    /** Illegal transitions are rejected for every non-listed pair. */
    public function test_illegal_transitions_are_rejected(): void
    {
        $this->assertTrue($this->machine->canTransition('DETECTED', 'ACKNOWLEDGED'));
        $this->assertTrue($this->machine->canTransition('DETECTED', 'RESOLVED'));
        $this->assertTrue($this->machine->canTransition('ACKNOWLEDGED', 'RESOLVED'));

        // No path back to DETECTED, no un-resolve, no ACKNOWLEDGED -> DETECTED.
        $this->assertFalse($this->machine->canTransition('DETECTED', 'DETECTED'));
        $this->assertFalse($this->machine->canTransition('ACKNOWLEDGED', 'DETECTED'));
        $this->assertFalse($this->machine->canTransition('RESOLVED', 'ACKNOWLEDGED'));
    }

    /** Manual resolution is distinguishable from auto in the audit trail (FR-59). */
    public function test_manual_vs_auto_resolution_are_distinguishable(): void
    {
        $manual = $this->makeIncident();
        $admin = $this->admin();
        $resolvedManual = $this->machine->resolveManually($manual, $admin, 'mitigated upstream');

        $auto = $this->makeIncident();
        $resolvedAuto = $this->machine->resolveAutomatically($auto, 'sustained recovery');

        $this->assertSame('manual', $resolvedManual->resolution_mode);
        $this->assertSame($admin->id, $resolvedManual->resolved_by);
        $this->assertNotNull($resolvedManual->resolved_by);

        $this->assertSame('auto', $resolvedAuto->resolution_mode);
        $this->assertNull($resolvedAuto->resolved_by);

        $autoEvent = $auto->events()->where('event_type', 'auto_resolved')->first();
        $this->assertNotNull($autoEvent);
        $this->assertNull($autoEvent->actor_user_id);
    }

    /** Idempotent replay of a transition is a no-op (NFR-09). */
    public function test_replaying_a_transition_is_idempotent(): void
    {
        $incident = $this->makeIncident();
        $admin = $this->admin();

        $first = $this->machine->acknowledge($incident, $admin);
        $replay = $this->machine->acknowledge($first, $admin);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame('ACKNOWLEDGED', $replay->status);
        $this->assertSame(
            1,
            $first->events()->where('event_type', 'acknowledged')->count(),
            'A replayed transition must not append a duplicate event.',
        );
    }
}
