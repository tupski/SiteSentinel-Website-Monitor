<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Check;
use App\Models\CheckExtraction;
use App\Models\Website;
use App\Models\WebsiteBaseline;
use App\Services\Detection\RuleEngine;
use App\Services\Detection\SnapshotWriter;
use App\Services\Incidents\IncidentEngine;
use App\Services\Monitor\Probe;
use App\Services\Monitor\ProbeResult;
use App\Services\Notifications\NotificationIntents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one monitoring check for a website (Phase 4 probe + Phase 5 detection).
 *
 * Idempotency (ARCHITECTURE.md 5.1, DETECTION-RULES 6.4): the check is keyed by
 * a deterministic `check_key`. Re-running the same logical check reuses the
 * existing row instead of violating the unique constraint, so a retried job
 * converges on exactly one persisted result.
 */
final class RunWebsiteCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public Website $website) {}

    public function handle(
        Probe $probe,
        RuleEngine $engine,
        SnapshotWriter $snapshotWriter,
        ?IncidentEngine $incidentEngine = null,
    ): void {
        $lock = Cache::lock('website-check:'.$this->website->id, 120);

        if (! $lock->get()) {
            // Another worker is already checking this website.
            return;
        }

        try {
            $this->run($probe, $engine, $snapshotWriter, $incidentEngine ?? app(IncidentEngine::class));
        } finally {
            $lock->release();
        }
    }

    private function run(
        Probe $probe,
        RuleEngine $engine,
        SnapshotWriter $snapshotWriter,
        IncidentEngine $incidentEngine,
    ): void {
        $key = $this->checkKey();

        // Idempotency guard: an identical logical check was already persisted.
        if (Check::where('check_key', $key)->exists()) {
            return;
        }

        $startedAt = now();
        $result = $probe->probe($this->website);

        /** @var array{0: Check, 1: ?CheckExtraction} $persisted */
        $persisted = DB::transaction(function () use ($key, $startedAt, $result, $engine) {
            $check = $this->persistCheck($key, $startedAt, $result);
            $extraction = $this->persistExtraction($check, $result);

            $this->updateCounters($result);

            $detection = $engine->evaluate(
                $this->website,
                $check,
                $extraction,
                $this->website->currentBaseline,
                $this->priorChecks($check),
            );

            $check->security_state = $detection->securityState;
            $check->score = $detection->score;
            $check->triggered_rules = $detection->triggeredRules();
            $check->save();

            $this->website->last_checked_at = now();
            $this->website->next_check_at = now()->addSeconds($this->website->check_interval_seconds);
            $this->website->status_availability = $check->availability_state;
            $this->website->status_security = $detection->securityState;
            $this->website->save();

            return [$check, $extraction];
        });

        [$check, $extraction] = $persisted;

        // Snapshot capture is a side effect and must never roll back the check
        // (AGENTS.md 9). It runs outside the transaction.
        $snapshot = null;
        if (in_array($check->security_state, ['SUSPECT', 'INCIDENT'], true)) {
            $snapshot = $snapshotWriter->capture(
                $check,
                (string) ($result->body ?? ''),
                is_array($result->headers) ? $result->headers : [],
                [
                    'keywords' => $extraction?->keywords,
                    'external_links' => $extraction?->external_domains,
                ],
            );
        }

        // Incident reconciliation (PLAN.md Phase 6) is also a side effect of the
        // check: a failure here must never invalidate the persisted check row.
        // Counters (consecutive_failures/successes) are already committed, so
        // threshold evaluation is consistent with what the check recorded.
        // Notification intents enqueue after-commit only, never inside a transaction.
        $before = NotificationIntents::snapshotOpen($this->website->id);

        try {
            $incident = $incidentEngine->processCheck($this->website->refresh(), $check, [
                'security_state' => $check->security_state,
                'score' => $check->score,
                'triggered_rules' => $check->triggered_rules,
            ]);

            // Evidence linkage (DATABASE.md 3.12): the snapshot captured for this
            // check belongs to the open incident it escalated.
            if ($snapshot !== null && $incident !== null) {
                $snapshot->incident_id = $incident->id;
                $snapshot->save();
            }
        } catch (Throwable $e) {
            report($e);

            Log::warning('Incident reconciliation failed', [
                'website_id' => $this->website->id,
                'check_id' => $check->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            NotificationIntents::enqueueFromDiff($this->website->id, $before);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function persistCheck(string $key, Carbon $startedAt, ProbeResult $result): Check
    {
        return Check::create([
            'website_id' => $this->website->id,
            'check_key' => $key,
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
    }

    private function persistExtraction(Check $check, ProbeResult $result): ?CheckExtraction
    {
        $keywords = $result->extractedKeywords ?? [];
        $domains = $result->extractedDomains ?? [];
        $patterns = $result->suspiciousPatterns ?? [];

        if ($keywords === [] && $domains === [] && $patterns === []) {
            return null;
        }

        return CheckExtraction::create([
            'check_id' => $check->id,
            'website_id' => $this->website->id,
            'keywords' => $keywords,
            'external_domains' => $domains,
            'suspicious_patterns' => $patterns,
        ]);
    }

    private function updateCounters(ProbeResult $result): void
    {
        if ($result->success) {
            $this->ensureBaseline($result);
            $this->website->consecutive_successes = $this->website->consecutive_successes + 1;
            $this->website->consecutive_failures = 0;
        } else {
            $this->website->consecutive_failures = $this->website->consecutive_failures + 1;
            $this->website->consecutive_successes = 0;
        }
    }

    /** @return Collection<int, Check> */
    private function priorChecks(Check $check): Collection
    {
        return Check::where('website_id', $this->website->id)
            ->where('id', '<', $check->id)
            ->orderByDesc('id')
            ->limit(RuleEngine::LOOKBACK_CHECKS)
            ->get();
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

        $domains = is_array($result->extractedDomains) ? $result->extractedDomains : [];
        $keywords = is_array($result->extractedKeywords) ? $result->extractedKeywords : [];

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
            'keyword_counts' => $keywords,
            'external_link_count' => count($domains),
            'external_domains' => array_values($domains),
            'ssl_valid' => $result->sslValid,
            'ssl_issuer' => $result->sslIssuer,
            'ssl_expires_at' => $result->sslExpiresAt,
            'captured_at' => now(),
        ]);

        $this->website->current_baseline_id = $baseline->id;
    }
}
