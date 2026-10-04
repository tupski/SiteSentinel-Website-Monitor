<?php

declare(strict_types=1);

namespace App\Contracts;

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
