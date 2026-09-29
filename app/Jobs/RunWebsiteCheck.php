<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Check;
use App\Models\Website;
use App\Services\Monitor\Probe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $startedAt = now();
        $result = $probe->probe($this->website);

        Check::create([
            'website_id' => $this->website->id,
            'check_key' => 'manual-'.uniqid(),
            'started_at' => $startedAt,
            'finished_at' => now(),
            'duration_ms' => $result->durationMs,
            'http_status' => $result->httpStatus,
            'final_url' => $result->finalUrl,
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
    }
}
