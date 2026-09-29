<?php

declare(strict_types=1);

use App\Jobs\RunWebsiteCheck;
use App\Models\Website;
use Illuminate\Support\Facades\Schedule;

// Phase 1 scheduler foundation (AC-1-03). The monitoring-plane jobs
// (dispatch of due website checks, retention pruning) are registered by
// their owning phases (PLAN.md Phase 4 / Phase 9) — nothing else is scheduled yet.
Schedule::command('model:prune')->daily();

// Phase 4: dispatch due website checks to the monitoring queue every minute.
Schedule::call(function (): void {
    $websites = Website::query()
        ->where('is_active', true)
        ->where(function ($query) {
            $query->whereNull('last_checked_at')
                ->orWhereColumn('last_checked_at', '<=', now()->subSeconds('check_interval_seconds'));
        })
        ->get();

    foreach ($websites as $website) {
        RunWebsiteCheck::dispatch($website);
    }
})->everyMinute()->name('sitesentinel:dispatch-due-checks')->withoutOverlapping();
