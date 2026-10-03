<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DeliveryResult;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Website;
use App\Services\Notifications\CircuitBreaker;
use App\Services\Notifications\MessageRedactor;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Single-channel delivery job on the `notifications` queue (NOTIFICATIONS §4/§12).
 *
 * Idempotent: checks the sent-row before sending. Provider exceptions become
 * DeliveryResult. Honors 429 retry_after. Logs queued/sent/failed.
 */
final class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $incidentId,
        public int $channelId,
        public string $eventKind,
        public ?int $actorId = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        /** @var array<int, int> $backoff */
        $backoff = config('sentinel.notifications.retry_backoff_seconds', [60, 300, 900]);

        return $backoff;
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2)->toDateTime();
    }

    public function handle(
        NotificationProviderRegistry $registry,
        CircuitBreaker $breaker,
    ): void {
        /** @var Incident|null $incident */
        $incident = Incident::query()->find($this->incidentId);

        /** @var NotificationChannel|null $channel */
        $channel = NotificationChannel::query()->find($this->channelId);

        if ($incident === null || $channel === null || ! $channel->enabled) {
            return;
        }

        /** @var Website|null $website */
        $website = Website::query()->find($incident->website_id);

        if ($website === null) {
            return;
        }

        $dedupeKey = NotificationDispatcher::dedupeKey($incident->id, $this->eventKind, (int) $channel->id);

        // Idempotency: a sent-row already exists (retry, restart, duplicate job).
        if (NotificationLog::query()->where('dedupe_key', $dedupeKey)->where('status', 'sent')->exists()) {
            return;
        }

        $queued = NotificationLog::create([
            'incident_id' => $incident->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'attempt' => $this->attempts(),
            'dedupe_key' => $dedupeKey,
        ]);

        $result = $this->deliver($registry, $incident, $website, $channel);

        if ($result->ok) {
            $queued->status = 'sent';
            $queued->attempt = max(1, $this->attempts());
            $queued->provider_message_id = $result->provider_message_id;
            $queued->sent_at = now();
            $queued->save();

            return;
        }

        $breaker->recordOutcome($channel->refresh(), false, $result->retryable);

        $queued->status = 'failed';
        $queued->attempt = max(1, $this->attempts());
        $queued->error = MessageRedactor::redact(
            ($result->error_code !== null ? '['.$result->error_code.'] ' : '').($result->error_message ?? 'delivery failed')
        );
        $queued->save();

        if (! $result->retryable) {
            // Dead-letter must reach failed_jobs (NOTIFICATIONS.md §12.3):
            // fail() alone returns normally, so the worker would delete the
            // job as succeeded. Throwing after fail lets the worker record it.
            $error = new \RuntimeException((string) $queued->error);
            $this->fail($error);

            throw $error;
        }

        $delay = $this->retryDelaySeconds($result);
        $this->release($delay);
    }

    private function deliver(
        NotificationProviderRegistry $registry,
        Incident $incident,
        Website $website,
        NotificationChannel $channel,
    ): DeliveryResult {
        try {
            $type = (string) $channel->type;
            $provider = $registry->resolve($type);

            if (! $provider->supports($this->eventKind)) {
                return new DeliveryResult(ok: false, error_code: 'unsupported_event', error_message: "provider {$type} skips {$this->eventKind}", retryable: false);
            }

            $config = is_array($channel->config) ? $channel->config : [];
            $secret = $channel->secret_ref !== null ? (string) $channel->secret_ref : null;

            // Rebind provider with live channel config/secret.
            $provider = app($provider::class, ['channelConfig' => $config, 'channelSecret' => $secret]);

            $payload = NotificationDispatcher::buildPayload($incident, $website, $this->eventKind, (int) $channel->id);

            return $provider->send($payload);
        } catch (Throwable $e) {
            report($e);

            return new DeliveryResult(ok: false, error_code: 'provider_exception', error_message: MessageRedactor::redact($e->getMessage()), retryable: true);
        }
    }

    private function retryDelaySeconds(DeliveryResult $result): int
    {
        // Honor 429 retry_after embedded as `retry_after=N` in error message.
        if ($result->error_code === 'rate_limited' && preg_match('/retry_after=(\d+)/', (string) $result->error_message, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        $backoff = $this->backoff();
        $index = min(max(0, $this->attempts() - 1), count($backoff) - 1);

        return (int) ($backoff[$index] ?? 60);
    }
}
