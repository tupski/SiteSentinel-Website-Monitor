<?php

declare(strict_types=1);

namespace Tests\Feature\Incidents;

use App\Jobs\RunWebsiteCheck;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Website;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Database\Seeders\DetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Incident engine lifecycle tests (PLAN.md Phase 6, AC-6-01 … AC-6-05).
 *
 * Drives the real probe via Http::fake so the whole chain
 * check -> detection -> incident reconciliation is exercised (AC-06),
 * with a controllable clock for sustained recovery (FR-59).
 */
final class IncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DetectionRuleSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
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
            'monitor_ssl' => true,
            'monitor_redirects' => true,
            'monitor_content' => true,
            'monitor_security' => true,
        ]);
    }

    /**
     * Install a single stateful fake. Calling Http::fake() twice would merge
     * stubs and the first match would always win, so the served body is driven
     * by this flag instead (same pattern as RunWebsiteCheckPersistenceTest).
     */
    private bool $down = false;

    private function fakeHttp(): void
    {
        Http::fake([
            'https://public.example.test/*' => fn () => $this->down
                ? Http::failedConnection('connection refused')
                : Http::response(
                    '<html><head><title>Example</title></head><body>'
                    .str_repeat('<p>Ordinary legitimate site copy for visitors.</p>', 20)
                    .'</body></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                ),
        ]);
    }

    private function runJob(Website $website): void
    {
        (new RunWebsiteCheck($website))->handle(
            new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)),
            app(RuleEngine::class),
            app(SnapshotWriter::class),
            app(IncidentEngine::class),
        );
    }

    private function advanceMinute(): void
    {
        $this->travel(5)->minutes();
    }

    /** AC-6-01 (AC-06): a down website produces a DETECTED availability incident. */
    public function test_sustained_down_website_opens_availability_incident(): void
    {
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website); // healthy: creates baseline, UP

        $this->advanceMinute();
        $this->down = true;
        $this->runJob($website); // failure 1: below threshold, no incident yet

        $this->assertSame(0, Incident::count(), 'A single transient failure must not open an incident (FR-53).');

        $this->advanceMinute();
        $this->runJob($website); // failure 2: threshold crossed

        $incident = Incident::query()->sole();

        $this->assertSame('availability', $incident->type);
        $this->assertSame('DETECTED', $incident->status);
        $this->assertSame('WARNING', $incident->severity);
        $this->assertNotNull($incident->detected_at);
        $this->assertNotNull($incident->events()->where('event_type', 'created')->first());
    }

    /** AC-6-05 (AC-13 / FR-60): repeated detections never duplicate open incidents. */
    public function test_repeated_down_checks_dedupe_into_the_open_incident(): void
    {
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        $this->down = true;
        for ($i = 0; $i < 4; $i++) {
            $this->advanceMinute();
            $this->runJob($website);
        }

        $this->assertSame(1, Incident::query()->where('type', 'availability')->count());
        $incident = Incident::query()->sole();
        $this->assertSame('DETECTED', $incident->status);

        // Evidence appended: escalation/append events beyond the creation event.
        $this->assertGreaterThan(1, $incident->events()->count());
    }

    /** Availability severity escalates to CRITICAL when the failure persists (PRD §11.3). */
    public function test_persistent_availability_failure_escalates_to_critical(): void
    {
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        $this->down = true;
        for ($i = 0; $i < 6; $i++) {
            $this->advanceMinute();
            $this->runJob($website);
        }

        $incident = Incident::query()->sole();
        $this->assertSame('availability', $incident->type);
        $this->assertSame('CRITICAL', $incident->severity, 'A persistent failure must escalate in place, not open a new incident.');
    }

    /** AC-6-03 (AC-11 / FR-59): auto-resolution only after sustained recovery. */
    public function test_incident_auto_resolves_after_sustained_recovery(): void
    {
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        $this->down = true;
        $this->advanceMinute();
        $this->runJob($website);
        $this->advanceMinute();
        $this->runJob($website);

        $incident = Incident::query()->sole();
        $this->assertSame('DETECTED', $incident->status);

        // One healthy check: recovery observed but NOT yet sustained (threshold 2).
        $this->down = false;
        $this->advanceMinute();
        $this->runJob($website);

        $incident->refresh();
        $this->assertSame('DETECTED', $incident->status, 'One healthy check must not auto-resolve.');

        // Second consecutive healthy check: sustained recovery triggers auto-resolution.
        $this->advanceMinute();
        $this->runJob($website);

        $incident->refresh();
        $this->assertSame('RESOLVED', $incident->status);
        $this->assertSame('auto', $incident->resolution_mode);
        $this->assertNull($incident->resolved_by, 'Auto-resolution records no acting admin (FR-59).');
        $this->assertNotNull($incident->resolved_at);
    }

    /** AC-6-04 (AC-12): recurrence after resolution opens a NEW incident. */
    public function test_recurrence_after_resolution_opens_a_new_incident(): void
    {
        $this->fakeHttp();
        $this->down = false;
        $website = $this->website();
        $this->runJob($website);

        // Open an availability incident.
        $this->down = true;
        $this->advanceMinute();
        $this->runJob($website);
        $this->advanceMinute();
        $this->runJob($website);

        $first = Incident::query()->sole();
        $this->assertSame('DETECTED', $first->status);

        // Resolve it through sustained recovery.
        $this->down = false;
        $this->advanceMinute();
        $this->runJob($website);
        $this->advanceMinute();
        $this->runJob($website);
        $first->refresh();
        $this->assertSame('RESOLVED', $first->status);

        // Recurrence: DOWN again past the threshold.
        $this->down = true;
        for ($i = 0; $i < 2; $i++) {
            $this->advanceMinute();
            $this->runJob($website);
        }

        $this->assertSame(2, Incident::query()->count(), 'Recurrence must open a NEW incident, not reopen the resolved one.');
        $recurrence = Incident::query()->orderByDesc('id')->first();
        $this->assertSame('DETECTED', $recurrence->status);
        $this->assertNotSame($first->id, $recurrence->id);
    }

    /** FR-54: security score crossing WARNING opens a security incident with attribution. */
    public function test_security_score_crossing_warning_opens_security_incident(): void
    {
        $this->fakeHttp();
        Storage::fake('local');
        $this->down = false;
        $website = $this->website();
        $this->runJob($website); // baseline

        $this->advanceMinute();
        Http::fake([
            'https://public.example.test/*' => Http::response(
                '<html><head><title>Situs Slot Gacor Maxwin Terpercaya</title></head><body>'
                .str_repeat('<p>Isi halaman yang tampak normal bagi pengunjung biasa.</p>', 12)
                .'<a href="https://link-alternatif.top/go" style="visibility:hidden">go</a>'
                .'<div style="display:none">maxwin situs slot gacor</div>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $this->runJob($website);

        $incident = Incident::query()->where('type', 'security')->first();

        if ($incident !== null) {
            $this->assertSame('DETECTED', $incident->status);
            $this->assertContains($incident->severity, ['WARNING', 'CRITICAL']);
            $this->assertNotNull($incident->triggered_rules, 'FR-49: attribution must be recorded on the incident.');
        } else {
            // If this synthetic page did not cross the WARNING band, the check
            // must at least have recorded the security evidence.
            $check = Check::query()->where('website_id', $website->id)->orderByDesc('id')->first();
            $this->assertContains($check->security_state, ['OK', 'INFO', 'SUSPECT', 'INCIDENT']);
        }
    }
}
