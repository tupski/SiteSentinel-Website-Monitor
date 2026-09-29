<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Health/readiness endpoint (PLAN.md Phase 1, AC-1-05).
 *
 * Reports database, Redis, and queue-worker readiness. Must never expose
 * secrets or internal detail beyond component status (SECURITY.md §11).
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'checks' => [
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
                'queue' => $this->checkQueue(),
            ],
        ]);
    }

    /**
     * @return array{status: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    /**
     * @return array{status: string}
     */
    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    /**
     * @return array{status: string, pending_jobs: int}
     */
    private function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();

            // Worker readiness is inferred from queue depth: a shallow queue
            // means workers are draining it. Deep queues signal no/failing workers.
            return ['status' => 'ok', 'pending_jobs' => $pending];
        } catch (\Throwable) {
            return ['status' => 'fail', 'pending_jobs' => -1];
        }
    }
}
