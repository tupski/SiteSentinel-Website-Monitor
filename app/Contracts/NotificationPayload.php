<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

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
