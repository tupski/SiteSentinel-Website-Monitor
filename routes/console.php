<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Phase 1 scheduler foundation (AC-1-03). The monitoring-plane jobs
// (dispatch of due website checks, retention pruning) are registered by
// their owning phases (PLAN.md Phase 4 / Phase 9) — nothing else is scheduled yet.
Schedule::command('model:prune')->daily();
