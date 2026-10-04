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
     * Rehydrate from the plain-array cache artefact (STATUS-PAGE.md §9).
     *
     * The projection cache stores {@see toArray()} — never the object — so a
     * serializing store cannot return a `__PHP_Incomplete_Class` when
     * `config('cache.serializable_classes')` is false (Laravel's gadget-chain
     * default). This is the exact inverse of {@see toArray()}; the round trip
     * is lossless for the allowlisted fields the constructor requires.
     *
     * @param  array{banner?: string, services?: array<int, mixed>, updatedDayBucket?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array{opaqueIndex: string, displayName: string, publicLabel: string, dayBucket: string, sortIndex: int, responseBand?: string}> $services */
        $services = is_array($data['services'] ?? null) ? array_values($data['services']) : [];

        return new self(
            banner: (string) ($data['banner'] ?? 'Unknown'),
            services: $services,
            updatedDayBucket: (string) ($data['updatedDayBucket'] ?? ''),
        );
    }

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
