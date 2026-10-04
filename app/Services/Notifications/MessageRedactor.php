<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Log redaction helper (SECURITY.md §4.2, NOTIFICATIONS.md §11.3).
 *
 * Provider exceptions can embed SMTP passwords or Telegram bot tokens.
 * Every error string persisted to notification_logs MUST pass through here.
 */
final class MessageRedactor
{
    /**
     * Denylist fragments matched case-insensitively against `key=value`
     * style pairs. `bot_token` and `smtp` included per NOTIFICATIONS §11.3.
     *
     * @var list<string>
     */
    private const DENYLIST = [
        'password',
        'passwd',
        'token',
        'secret',
        'authorization',
        'cookie',
        'x-api-key',
        'bot_token',
        'smtp',
        // Browser Push material (SECURITY.md §4, ADR-032): VAPID private key
        // and subscription material must never survive redaction.
        'p256dh',
        'vapid_private_key',
        'private_key',
    ];

    public static function redact(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $keys = implode('|', array_map(preg_quote(...), self::DENYLIST));

        // key=value / key: value pairs -> key=[REDACTED]. Quote-aware so
        // JSON-style `"password":"secret"` (common in serialized exceptions
        // and HTTP client dumps) is redacted too.
        $redacted = (string) preg_replace(
            '/('.$keys.')(["\']?\s*[:=]\s*["\']?)([^\s;,\"\'\}]+)(["\']?)/i',
            '$1$2[REDACTED]$4',
            $message
        );

        // Authorization schemes carry the credential after a space
        // (`Authorization: Bearer <token>`); redact the whole value.
        $redacted = (string) preg_replace(
            '/(authorization\s*[:=]\s*)([^\r\n;]+)/i',
            '$1[REDACTED]',
            $redacted
        );

        // Raw Telegram bot token `123456:ABC-DEF...` appearing in URLs or
        // exceptions, with or without the `bot` URL prefix
        // (`api.telegram.org/bot123456:ABC.../sendMessage`).
        $redacted = (string) preg_replace(
            '/\bbot?\d{6,12}:[A-Za-z0-9_-]{30,}\b/',
            '[REDACTED-TELEGRAM-TOKEN]',
            $redacted
        );

        // SMTP URLs carrying credentials: smtp://user:pass@host.
        $redacted = (string) preg_replace(
            '/(smtps?:\/\/[^:\s\/]+:)([^@\s]+)(@)/i',
            '$1[REDACTED]$3',
            $redacted
        );

        return mb_substr($redacted, 0, 2000);
    }
}
