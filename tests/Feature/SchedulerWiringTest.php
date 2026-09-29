<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Phase 1 scheduler wiring (AC-1-03).
 *
 * Asserts scheduled tasks are registered and `schedule:list` resolves.
 * Monitoring-plane schedules (dispatch due checks, retention pruning) are
 * added by their owning phases (PLAN.md Phase 4 / Phase 9), not here.
 */
final class SchedulerWiringTest extends TestCase
{
    public function test_scheduler_has_registered_events(): void
    {
        $events = Schedule::events($this->app);

        $this->assertNotEmpty($events, 'scheduler must have at least one registered event');
    }

    public function test_schedule_list_resolves_without_error(): void
    {
        $output = Artisan::call('schedule:list');

        $this->assertSame(0, $output, 'schedule:list must exit cleanly');
    }
}
