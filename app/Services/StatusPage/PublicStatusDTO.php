<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

/**
 * Public-safe status projection (STATUS-PAGE.md §4, §8.1).
 *
 * Allowlist only: banner, services, updatedDayBucket, updatedAt. Nothing else
 * may be added without a redaction review. Never holds models, URLs, IPs, rule
 * ids, scores, or raw content.
 *
 * `updatedAt` is the precise UTC ISO-8601 projection generation stamp
 * (ADR-040) — the same instant that produces `updatedDayBucket`. It is a
 * freshness signal only: never an incident or check timestamp.
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
        public readonly string $updatedAt = '',
    ) {}

    /**
     * Rehydrate from the plain-array cache artefact (STATUS-PAGE.md §9).
     *
     * The projection cache stores {@see toArray()} — never the object — so a
     * serializing store cannot return a `__PHP_Incomplete_Class` when
     * `config('cache.serializable_classes')` is false (Laravel's gadget-chain
     * default). This is the exact inverse of {@see toArray()}; the round trip
     * is lossless for the allowlisted fields the constructor requires.
     *
     * @param  array{banner?: string, services?: array<int, mixed>, updatedDayBucket?: string, updatedAt?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array{opaqueIndex: string, displayName: string, publicLabel: string, dayBucket: string, sortIndex: int, responseBand?: string}> $services */
        $services = is_array($data['services'] ?? null) ? array_values($data['services']) : [];

        return new self(
            banner: (string) ($data['banner'] ?? 'Unknown'),
            services: $services,
            updatedDayBucket: (string) ($data['updatedDayBucket'] ?? ''),
            updatedAt: (string) ($data['updatedAt'] ?? ''),
        );
    }

    /**
     * @return array{banner: string, services: array<int, mixed>, updatedDayBucket: string, updatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'banner' => $this->banner,
            'services' => $this->services,
            'updatedDayBucket' => $this->updatedDayBucket,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * @return array{banner: string, services: array<int, mixed>, updatedDayBucket: string, updatedAt: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
