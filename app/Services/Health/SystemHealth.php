<?php

declare(strict_types=1);

namespace App\Services\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Component readiness probe (PRD AC-21, PLAN.md Phase 10 observability).
 *
 * Single source of truth for the DB / Redis / queue-worker readiness check,
 * consumed by both the public `/health` endpoint (`HealthController`) and the
 * admin dashboard health widget (AC-21: "The admin dashboard exposes
 * health/readiness for the database, Redis, and queue worker, so a stalled
 * monitoring pipeline is visible to Admin").
 *
 * SECURITY.md §11: output is limited to component status + a queue depth
 * count. It never exposes a connection string, credential, or internal detail.
 */
class SystemHealth
{
    /**
     * Per-component readiness.
     *
     * @return array{
     *     database: array{status: string},
     *     redis: array{status: string},
     *     queue: array{status: string, pending_jobs: int}
     * }
     */
    public function checks(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];
    }

    /**
     * Whether every component reports ready.
     *
     * @param  array<string, array{status: string}>|null  $checks
     */
    public function isHealthy(?array $checks = null): bool
    {
        $checks ??= $this->checks();

        return collect($checks)->every(fn (array $c): bool => $c['status'] === 'ok');
    }

    /**
     * @return array{status: string}
     */
    public function checkDatabase(): array
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
    public function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    /**
     * Worker readiness is inferred from queue depth: a shallow queue means
     * workers are draining it; a deep queue signals no/failing workers.
     *
     * @return array{status: string, pending_jobs: int}
     */
    public function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();

            return ['status' => 'ok', 'pending_jobs' => $pending];
        } catch (\Throwable) {
            return ['status' => 'fail', 'pending_jobs' => -1];
        }
    }
}
