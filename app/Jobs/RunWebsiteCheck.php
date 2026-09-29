<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Monitor\Probe;
use App\Services\Monitor\ProbeResult;
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

    public function handle(Probe $probe, RuleEngine $engine, SnapshotWriter $snapshotWriter): void
    {
        $lock = Cache::lock('website-check:'.$this->website->id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->run($probe, $engine, $snapshotWriter);
        } finally {
            $lock->release();
        }
    }

    private function run(Probe $probe, RuleEngine $engine, SnapshotWriter $snapshotWriter): void
    {
        $startedAt = now();
        $result = $probe->probe($this->website);

        DB::transaction(function () use ($startedAt, $result, $engine, $snapshotWriter) {
            $availabilityState = $result->success ? 'UP' : 'DOWN';

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
                'availability_state' => $availabilityState,
            ]);

            $extraction = $this->persistExtraction($check, $result);

            if ($result->success) {
                $this->ensureBaseline($result);
                $this->website->consecutive_successes = $this->website->consecutive_successes + 1;
                $this->website->consecutive_failures = 0;
            } else {
                $this->website->consecutive_failures = $this->website->consecutive_failures + 1;
                $this->website->consecutive_successes = 0;
            }

            $baseline = $this->website->currentBaseline;
            $priorChecks = Check::where('website_id', $this->website->id)
                ->where('id', '<', $check->id)
                ->orderBy('id', 'desc')
                ->limit(3)
                ->get();

            $detectionResult = $engine->evaluate($this->website, $check, $extraction, $baseline, $priorChecks);

            $check->security_state = $detectionResult->securityState;
            $check->score = $detectionResult->score;
            $check->save();

            if (in_array($detectionResult->securityState, ['SUSPECT', 'INCIDENT'], true)) {
                $snapshotWriter->capture($check, ['body' => $result->body ?? ''], $result->headers ?? []);
            }

            $this->website->last_checked_at = now();
            $this->website->next_check_at = now()->addSeconds($this->website->check_interval_seconds);
            $this->website->status_availability = $availabilityState;
            $this->website->status_security = $detectionResult->securityState;
            $this->website->save();
        });
    }

    private function persistExtraction(Check $check, ProbeResult $result): ?CheckExtraction
    {
        $keywords = $result->extractedKeywords ?? [];
        $domains = $result->extractedDomains ?? [];
        $suspicious = $result->suspiciousPatterns ?? [];

        if ($keywords === [] && $domains === [] && $suspicious === []) {
            return null;
        }

        return CheckExtraction::create([
            'check_id' => $check->id,
            'website_id' => $this->website->id,
            'keywords' => $keywords,
            'external_domains' => $domains,
            'suspicious_patterns' => $suspicious,
        ]);
    }

    private function checkKey(): string
    {
        return 'website:'.$this->website->id.':'.now()->format('Y-m-d-H-i');
    }

    private function ensureBaseline(ProbeResult $result): void
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
            'response_size_bytes' => $result->responseSizeBytes,
            'external_link_count' => count($result->extractedDomains ?? []),
            'external_domains' => $result->extractedDomains ?? [],
            'ssl_valid' => $result->sslValid,
            'ssl_issuer' => $result->sslIssuer,
            'ssl_expires_at' => $result->sslExpiresAt,
            'captured_at' => now(),
        ]);

        $this->website->current_baseline_id = $baseline->id;
    }
}
