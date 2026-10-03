<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

/**
 * Provider-independent notification contract (NOTIFICATIONS.md §2.3, ADR-010).
 *
 * The incident engine knows nothing about transports. Every provider
 * (Email, Telegram, future WhatsApp/Webhook) implements this boundary.
 */
interface NotificationProvider
{
    /**
     * Deliver one already-rendered payload. Must not throw for expected
     * delivery failures; return a DeliveryResult so the dispatcher owns
     * retry classification and logging.
     */
    public function send(NotificationPayload $payload): DeliveryResult;

    /**
     * Declare which event kinds this provider can express.
     */
    public function supports(string $eventKind): bool;

    /**
     * Validate channel configuration at save time and test-send time
     * without sending live traffic. Returns true when valid, otherwise
     * an array of human-readable error strings.
     *
     * @param  array<string, mixed>  $config
     */
    public function validateConfig(array $config, ?string $secret): bool|array;
}

/**
 * Provider-neutral payload DTO (NOTIFICATIONS.md §2.4).
 *
 * Carries summary + admin deep link only. Never secrets, credentials,
 * response bodies, keyword lists, domains, redirect targets, rule
 * ids/weights/scores, or snapshot contents (FR-73).
 */
final readonly class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $template_vars  §8.2 data contract only.
     */
    public function __construct(
        public int $incident_id,
        public int $website_id,
        public string $event_kind,
        public string $severity,
        public string $title,
        public string $summary,
        public DateTimeImmutable $detected_at,
        public string $admin_url,
        public string $dedupe_key,
        public array $template_vars = [],
    ) {}
}

/**
 * Structured provider outcome (NOTIFICATIONS.md §2.3).
 *
 * error_message must already be redacted per SECURITY.md §4.2 denylist.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public bool $ok,
        public ?string $provider_message_id = null,
        public ?string $error_code = null,
        public ?string $error_message = null,
        public bool $retryable = false,
        public int $latency_ms = 0,
    ) {}
}
