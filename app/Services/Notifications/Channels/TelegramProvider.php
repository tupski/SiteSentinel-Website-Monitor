<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Contracts\DeliveryResult;
use App\Contracts\NotificationPayload;
use App\Contracts\NotificationProvider;
use App\Services\Notifications\MessageRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram Bot API provider (NOTIFICATIONS.md §6).
 *
 * bot_token from decrypted secret_ref only, never logged. HTML mode with
 * strict escaping, 4096 cap preserving admin_url, parse-error fallback
 * once to plain text, per-chat Redis lock, 401/403/400 permanent vs
 * 5xx/timeout/429 retryable honoring retry_after.
 */
final class TelegramProvider implements NotificationProvider
{
    private const MAX_LENGTH = 4096;

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

        if (empty($config['chat_id'])) {
            $errors[] = 'telegram config missing: chat_id.';
        }

        if ($secret === null || $secret === '') {
            $errors[] = 'bot_token (secret_ref) required.';
        }

        return $errors === [] ? true : $errors;
    }

    public function send(NotificationPayload $payload): DeliveryResult
    {
        $started = (int) (microtime(true) * 1000);
        $token = (string) ($this->channelSecret ?? '');
        $chatId = (string) ($this->channelConfig['chat_id'] ?? '');

        if ($token === '' || $chatId === '') {
            return new DeliveryResult(ok: false, error_code: 'config_error', error_message: 'telegram channel misconfigured', retryable: false, latency_ms: $this->latency($started));
        }

        $lock = Cache::lock('telegram:chat:'.$chatId, 30);

        if (! $lock->get()) {
            return new DeliveryResult(ok: false, error_code: 'chat_locked', error_message: 'per-chat lock held', retryable: true, latency_ms: $this->latency($started));
        }

        try {
            $threadId = $this->channelConfig['message_thread_id'] ?? null;
            $text = $this->renderHtml($payload);

            $result = $this->post($token, $chatId, $text, true, $threadId);

            if ($result !== null && $result['parse_error'] === true) {
                $result = $this->post($token, $chatId, $this->renderPlain($payload), false, $threadId);
            }

            if ($result === null) {
                return new DeliveryResult(ok: false, error_code: 'transport_error', error_message: 'empty telegram response', retryable: true, latency_ms: $this->latency($started));
            }

            return new DeliveryResult(
                ok: $result['ok'],
                provider_message_id: $result['message_id'],
                error_code: $result['error_code'],
                error_message: MessageRedactor::redact($result['error_message']),
                retryable: $result['retryable'],
                latency_ms: $this->latency($started)
            );
        } catch (Throwable $e) {
            report($e);

            return new DeliveryResult(ok: false, error_code: 'transport_error', error_message: MessageRedactor::redact($e->getMessage()), retryable: true, latency_ms: $this->latency($started));
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{ok: bool, message_id: ?string, error_code: ?string, error_message: ?string, retryable: bool, parse_error: bool, retry_after: int|null}|null
     */
    private function post(string $token, string $chatId, string $text, bool $html, mixed $threadId): ?array
    {
        $body = ['chat_id' => $chatId, 'text' => $text];

        if ($html) {
            $body['parse_mode'] = 'HTML';
        }

        if ($threadId !== null && $threadId !== '') {
            $body['message_thread_id'] = (int) $threadId;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", $body);
        } catch (Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error_code' => 'timeout', 'error_message' => MessageRedactor::redact($e->getMessage()), 'retryable' => true, 'parse_error' => false, 'retry_after' => null];
        }

        $status = $response->status();
        $json = $response->json();

        if ($response->successful() && ($json['ok'] ?? false) === true) {
            $messageId = isset($json['result']['message_id']) ? (string) $json['result']['message_id'] : null;

            return ['ok' => true, 'message_id' => $messageId, 'error_code' => null, 'error_message' => null, 'retryable' => false, 'parse_error' => false, 'retry_after' => $this->retryAfter($json)];
        }

        $description = (string) ($json['description'] ?? $response->body() ?? 'telegram send failed');
        $retryAfter = $this->retryAfter($json);

        if ($status === 429) {
            return ['ok' => false, 'message_id' => null, 'error_code' => 'rate_limited', 'error_message' => $description.' retry_after='.($retryAfter ?? 0), 'retryable' => true, 'parse_error' => false, 'retry_after' => $retryAfter];
        }

        if (in_array($status, [400, 401, 403, 404], true)) {
            $isParseError = $status === 400 && stripos($description, 'parse') !== false;

            return ['ok' => false, 'message_id' => null, 'error_code' => 'telegram_'.$status, 'error_message' => $description, 'retryable' => false, 'parse_error' => $isParseError && $html, 'retry_after' => null];
        }

        return ['ok' => false, 'message_id' => null, 'error_code' => 'telegram_'.$status, 'error_message' => $description, 'retryable' => true, 'parse_error' => false, 'retry_after' => null];
    }

    /**
     * @param  mixed  $json
     */
    private function retryAfter($json): ?int
    {
        if (is_array($json) && isset($json['parameters']['retry_after'])) {
            return max(1, (int) $json['parameters']['retry_after']);
        }

        return null;
    }

    private function renderHtml(NotificationPayload $payload): string
    {
        $v = $payload->template_vars;
        $lines = [
            '<b>'.$this->e((string) ($payload->severity.' — '.($v['incident_type'] ?? 'incident').' detected')).'</b>',
            'Website: '.$this->e((string) ($v['website']['name'] ?? '')),
            'Type: '.$this->e((string) ($v['incident_type'] ?? '')),
            'Detected: '.$this->e((string) ($v['detected_at'] ?? '')).' (incident #'.$payload->incident_id.')',
            'Status: '.$this->e((string) ($v['current_status'] ?? '')),
            'Summary: '.$this->e($payload->summary),
            '',
            '<a href="'.$this->e($payload->admin_url).'">Open incident in admin</a>',
        ];

        return $this->cap(implode("\n", $lines), $payload->admin_url);
    }

    private function renderPlain(NotificationPayload $payload): string
    {
        $v = $payload->template_vars;
        $lines = [
            $payload->severity.' — '.($v['incident_type'] ?? 'incident').' detected',
            'Website: '.($v['website']['name'] ?? ''),
            'Summary: '.$payload->summary,
            '',
            $payload->admin_url,
        ];

        return $this->cap(implode("\n", $lines), $payload->admin_url);
    }

    private function cap(string $text, string $adminUrl): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $reserve = mb_strlen($adminUrl) + 30;
        $budget = max(200, self::MAX_LENGTH - $reserve);
        $cut = mb_substr($text, 0, $budget);

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $budget - 100) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return $cut."\n…\n".$adminUrl;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function latency(int $started): int
    {
        return max(0, (int) (microtime(true) * 1000) - $started);
    }
}
