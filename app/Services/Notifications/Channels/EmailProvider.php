<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Services\Notifications\MessageRedactor;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * SMTP email provider (NOTIFICATIONS.md §5).
 *
 * Uses Laravel mailer. Password from decrypted secret_ref only.
 * 4xx transient -> retryable, 5xx permanent.
 */
final class EmailProvider implements NotificationProvider
{
    /**
     * @param  array<string, mixed>  $channelConfig
     */
    public function __construct(
        private readonly array $channelConfig = [],
        private readonly ?string $channelSecret = null,
    ) {}

    public function supports(string $eventKind): bool
    {
        return true;
    }

    public function validateConfig(array $config, ?string $secret): bool|array
    {
        $errors = [];

        foreach (['host', 'port', 'from_address', 'recipients'] as $key) {
            if (empty($config[$key])) {
                $errors[] = "email config missing: {$key}.";
            }
        }

        if (! empty($config['recipients']) && ! is_array($config['recipients']) && ! is_string($config['recipients'])) {
            $errors[] = 'recipients must be a string or list.';
        }

        $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
        if (! in_array($encryption, ['tls', 'ssl'], true) && app()->isProduction()) {
            $errors[] = 'SMTP requires tls/ssl encryption in production.';
        }

        if ($secret === null || $secret === '') {
            $errors[] = 'SMTP password (secret_ref) required.';
        }

        return $errors === [] ? true : $errors;
    }

    public function send(NotificationPayload $payload): DeliveryResult
    {
        $started = (int) (microtime(true) * 1000);
        $config = $this->channelConfig;

        try {
            $recipients = $this->recipients($config);
            $from = (string) ($config['from_address'] ?? config('mail.from.address'));
            $fromName = (string) ($config['from_name'] ?? config('mail.from.name', 'SiteSentinel'));
            $vars = $payload->template_vars;
            $subject = sprintf(
                '[SiteSentinel] %s — %s: %s %s',
                $payload->severity,
                $vars['website']['name'] ?? 'website',
                $vars['incident_type'] ?? 'incident',
                str_replace('incident.', '', $payload->event_kind)
            );

            Mail::mailer($this->mailerName($config))->send(
                ['html' => 'emails.incident-html', 'text' => 'emails.incident-text'],
                $vars,
                function ($message) use ($recipients, $from, $fromName, $subject): void {
                    $message->from($from, $fromName);
                    $message->to($recipients);
                    $message->subject(mb_substr($subject, 0, 255));
                }
            );

            return new DeliveryResult(ok: true, latency_ms: $this->latency($started));
        } catch (TransportException $e) {
            return $this->classify($e, $started);
        } catch (Throwable $e) {
            report($e);

            return new DeliveryResult(ok: false, error_code: 'transport_error', error_message: MessageRedactor::redact($e->getMessage()), retryable: true, latency_ms: $this->latency($started));
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return string|list<string>
     */
    private function recipients(array $config): string|array
    {
        $recipients = $config['recipients'] ?? '';

        if (is_string($recipients) && str_contains($recipients, ',')) {
            return array_map(trim(...), explode(',', $recipients));
        }

        return $recipients;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mailerName(array $config): string
    {
        if (! empty($config['host'])) {
            config([
                'mail.mailers.sentinel-channel' => [
                    'transport' => 'smtp',
                    'host' => $config['host'],
                    'port' => (int) ($config['port'] ?? 587),
                    'encryption' => $config['encryption'] ?? 'tls',
                    'username' => $config['username'] ?? null,
                    'password' => $this->channelSecret,
                    'timeout' => 10,
                ],
            ]);

            return 'sentinel-channel';
        }

        return (string) config('mail.default', 'log');
    }

    private function classify(TransportException $e, int $started): DeliveryResult
    {
        $message = (string) $e->getMessage();
        $latency = $this->latency($started);

        if (preg_match('/\b5\d\d\b|invalid (recipient|address|mailbox)|mailbox unavailable/i', $message) === 1) {
            return new DeliveryResult(ok: false, error_code: 'smtp_5xx', error_message: MessageRedactor::redact($message), retryable: false, latency_ms: $latency);
        }

        return new DeliveryResult(ok: false, error_code: 'smtp_4xx', error_message: MessageRedactor::redact($message), retryable: true, latency_ms: $latency);
    }

    private function latency(int $started): int
    {
        return max(0, (int) (microtime(true) * 1000) - $started);
    }
}
