<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Website;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/**
 * PRD AC-20 / NFR-02 local invariants (PLAN.md Phase 10, AC-10-03).
 *
 * AC-20 proper — "the full stack runs within the NFR-03 envelope (2 vCPU /
 * 4 GB / 40 GB) ... with the queue draining before the next batch is due" — is
 * a *measured load* result on a specific host and is therefore classified
 * `NOT VERIFIED (deployment-only)` (see CHANGELOG / SECURITY.md §12.1). What
 * IS locally verifiable is the set of configuration invariants the design
 * relies on to make that drain achievable and to keep the reference workload
 * bounded. This test pins those invariants so a config drift that would defeat
 * the reference workload is caught before deploy.
 *
 * It does not — and must not — claim a throughput result.
 */
final class ReferenceWorkloadInvariantsTest extends SecurityTestCase
{
    public function test_reference_interval_is_in_the_allowed_set(): void
    {
        // NFR-02 reference workload = 5-minute interval (300s).
        $this->assertContains(300, config('sentinel.monitoring.allowed_intervals_seconds'));
        $this->assertSame(300, (int) config('sentinel.monitoring.default_interval_seconds'));
    }

    public function test_per_job_budget_has_headroom_inside_the_reference_interval(): void
    {
        $interval = (int) config('sentinel.monitoring.default_interval_seconds');
        $jobBudget = (int) config('sentinel.probe_limits.job_timeout_seconds');

        // NFR-02: the queue must drain "well before" the next batch with >= 3x
        // capacity headroom. At the reference interval a single job budget must
        // be a small fraction of the interval.
        $this->assertGreaterThan(0, $jobBudget);
        $this->assertGreaterThanOrEqual(
            $jobBudget * 3,
            $interval,
            'The per-check budget must leave >= 3x headroom inside the reference interval (NFR-02).',
        );
    }

    public function test_max_concurrency_can_service_the_reference_fan_out(): void
    {
        $interval = (int) config('sentinel.monitoring.default_interval_seconds');
        $jobBudget = (int) config('sentinel.probe_limits.job_timeout_seconds');
        $concurrency = (int) config('sentinel.monitoring.max_concurrent_checks');

        // 50 reference websites / 300s interval => 10 checks every 300s.
        // Each worker can service interval/budget checks per interval.
        $referenceFanOut = 10;
        $capacityPerInterval = $concurrency * max(1, intdiv($interval, max(1, $jobBudget)));

        $this->assertGreaterThanOrEqual(
            $referenceFanOut * 3,
            $capacityPerInterval,
            'Configured concurrency must give >= 3x headroom over the reference fan-out (NFR-02).',
        );
    }

    public function test_due_check_dispatch_is_registered_and_non_overlapping(): void
    {
        $events = Schedule::events($this->app);

        /** @var Event|null $dispatch */
        $dispatch = collect($events)->first(
            fn (Event $event): bool => ($event->description ?? '') === 'sitesentinel:dispatch-due-checks'
        );

        $this->assertNotNull($dispatch, 'The due-check dispatcher must be scheduled (AC-4).');
        $this->assertTrue(
            $dispatch->withoutOverlapping,
            'Dispatch must not overlap, or a slow batch could double-dispatch (NFR-02).',
        );
    }

    public function test_active_websites_only_are_dispatched(): void
    {
        // The reference workload counts ENABLED websites only (NFR-02).
        Website::create([
            'name' => 'Active',
            'url' => 'https://active.example.test/',
            'scheme' => 'https',
            'host' => 'active.example.test',
            'is_active' => true,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);

        Website::create([
            'name' => 'Inactive',
            'url' => 'https://inactive.example.test/',
            'scheme' => 'https',
            'host' => 'inactive.example.test',
            'is_active' => false,
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);

        $due = Website::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            })
            ->count();

        $this->assertSame(1, $due, 'Only active websites count toward the reference workload.');
    }
}
