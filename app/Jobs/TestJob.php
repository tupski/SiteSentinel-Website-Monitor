<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 1 queue round-trip probe (AC-1-02).
 *
 * Dispatched by tests/operations to verify the queue pipeline
 * (Redis in production; sync/database fallback locally) end to end.
 * Writes a marker row-free signal: stores completion timestamp in cache.
 */
final class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $marker
    ) {}

    public function handle(): void
    {
        cache()->put(
            'testjob:'.$this->marker,
            now()->toIso8601String(),
            now()->addMinutes(5)
        );
    }
}
