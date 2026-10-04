<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Health\SystemHealth;
use Illuminate\Http\JsonResponse;

/**
 * Health/readiness endpoint (PLAN.md Phase 1, AC-1-05).
 *
 * Reports database, Redis, and queue-worker readiness. Must never expose
 * secrets or internal detail beyond component status (SECURITY.md §11).
 *
 * The readiness logic lives in `SystemHealth` so the admin dashboard can
 * surface the same signal (PRD AC-21). The Phase 1 response envelope
 * (`status` + `checks.{database,redis,queue}`) is preserved exactly.
 */
final class HealthController extends Controller
{
    public function __invoke(SystemHealth $health): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'checks' => $health->checks(),
        ]);
    }
}
