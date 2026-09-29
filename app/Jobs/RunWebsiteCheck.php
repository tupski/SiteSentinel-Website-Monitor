<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Check;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Services\Monitor\Probe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RunWebsiteCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public Website $website) {}

    public function handle(Probe $probe): void
    {
        $lock = Cache::lock('website-check:'.$this->website->id, 120);

        if (! $lock->get()) {
            // Another worker is already checking this website.
            return;
        }

        try {
            $this->run($probe);
        } finally {
            $lock->release();
        }
    }

    private function run(Probe $probe): void
    {
        $startedAt = now();
        $result = $probe->probe($this->website);

        DB::transaction(function () use ($startedAt, $result) {
            $check = Check::create([
                'website_id' => $this->website->id,
                'check_key' => $this->checkKey(),
                'started_at' => $startedAt,
                'finished_at' => now(),
                'duration_ms' => $result->durationMs,
                'http_status' => $result->httpStatus,
                'final_url' => $result->finalUrl,
                'resolved_ip' => $result->resolvedIp,
                'redirect_chain' => $result->redirectChain,
                'response_size_bytes' => $result->responseSizeBytes,
                'ssl_valid' => $result->sslValid,
                'ssl_issuer' => $result->sslIssuer,
                'ssl_expires_at' => $result->sslExpiresAt,
                'title' => $result->title,
                'content_hash' => $result->contentHash,
                'error_type' => $result->errorType,
                'error_message' => $result->errorMessage,
                'availability_state' => $result->success ? 'UP' : 'DOWN',
            ]);

            if ($result->success) {
                $this->ensureBaseline($result);
                $this->website->consecutive_successes = $this->website->consecutive_successes + 1;
                $this->website->consecutive_failures = 0;
            } else {
                $this->website->consecutive_failures = $this->website->consecutive_failures + 1;
                $this->website->consecutive_successes = 0;
            }

            $this->website->last_checked_at = now();
            $this->website->next_check_at = now()->addSeconds($this->website->check_interval_seconds);
            $this->website->save();
        });
    }

    private function checkKey(): string
    {
        return 'website:'.$this->website->id.':'.now()->format('Y-m-d-H-i');
    }

    private function ensureBaseline(mixed $result): void
    {
        if ($this->website->current_baseline_id !== null) {
            return;
        }

        $baseline = WebsiteBaseline::create([
            'website_id' => $this->website->id,
            'version' => 1,
            'is_active' => true,
            'http_status' => $result->httpStatus,
            'final_url' => $result->finalUrl,
            'title' => $result->title,
            'content_hash' => $result->contentHash,
            'hash_algorithm' => 'sha256',
            'external_link_count' => 0,
            'ssl_valid' => $result->sslValid,
            'ssl_issuer' => $result->sslIssuer,
            'ssl_expires_at' => $result->sslExpiresAt,
            'captured_at' => now(),
        ]);

        $this->website->current_baseline_id = $baseline->id;
    }
}
