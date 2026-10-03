<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

/**
 * Public-safe status projection (STATUS-PAGE.md §4).
 *
 * Allowlist only: banner, services, updatedDayBucket. Nothing else may be
 * added without a redaction review. Never holds models, URLs, IPs, rule
 * ids, scores, or raw content.
 */
final class PublicStatusDTO implements \JsonSerializable
{
    /**
     * @param  array<int, array{opaqueIndex: string, displayName: string, publicLabel: string, dayBucket: string, sortIndex: int, responseBand?: string}>  $services
     */
    public function __construct(
        public readonly string $banner,
        public readonly array $services,
        public readonly string $updatedDayBucket,
    ) {}

    /**
     * @return array{banner: string, services: array<int, mixed>, updatedDayBucket: string}
     */
    public function toArray(): array
    {
        return [
            'banner' => $this->banner,
            'services' => $this->services,
            'updatedDayBucket' => $this->updatedDayBucket,
        ];
    }

    /**
     * @return array{banner: string, services: array<int, mixed>, updatedDayBucket: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
