<?php

declare(strict_types=1);

namespace App\Services\Detection;

/**
 * Result of running the detection engine against a single check.
 */
final readonly class DetectionResult
{
    /**
     * @param  Signal[]  $signals
     */
    public function __construct(
        public string $availabilityState,
        public string $securityState,
        public int $score,
        public bool $guardCapped,
        public array $signals,
        public ?int $snapshotId = null,
    ) {}
}
