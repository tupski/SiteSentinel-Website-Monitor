<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TestJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 queue round-trip (AC-1-02).
 *
 * Dispatches TestJob onto the *database* queue connection (works in every
 * environment), asserts the job is pending, then processes it through a
 * real `queue:work --once` worker and asserts the completion marker.
 *
 * Redis-backed queueing (production QUEUE_CONNECTION=redis) was verified
 * against a live Redis during Phase 1 verification; this test proves the
 * queue pipeline mechanics independently of the Redis daemon.
 */
final class QueueRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_round_trip_dispatches_queues_and_processes_a_job(): void
    {
        config(['queue.default' => 'database']);

        $marker = 'test-'.bin2hex(random_bytes(4));

        TestJob::dispatch($marker)->onConnection('database');

        // Job is queued, not yet executed
        $pending = DB::table('jobs')->where('queue', 'default')->count();
        $this->assertSame(1, $pending, 'job must sit in the queue before a worker runs');
        $this->assertFalse(Cache::has('testjob:'.$marker));

        Artisan::call('queue:work', [
            '--once' => true,
            '--sleep' => '0',
            '--tries' => '1',
        ]);

        // Worker drained the job and the handler wrote its marker
        $this->assertSame(0, DB::table('jobs')->count(), 'worker must consume the job');
        $this->assertNotNull(
            cache()->get('testjob:'.$marker),
            'processed job must write its completion marker'
        );
    }
}
