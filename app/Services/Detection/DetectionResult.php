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
     * @param  Signal[]  $carriedSignals
     */
    public function __construct(
        public string $availabilityState,
        public string $securityState,
        public int $score,
        public bool $guardCapped,
        public array $signals,
        public array $carriedSignals = [],
        public ?int $snapshotId = null,
    ) {}

    /** Attribution map persisted to `checks.triggered_rules` (FR-49, §6.1). */
    public function triggeredRules(): array
    {
        $map = [];
        foreach ($this->signals as $signal) {
            $map[$signal->ruleId] = [
                'category' => $signal->category,
                'weight' => $signal->weight,
                'confidence' => $signal->confidence,
                'reason' => $signal->reason,
            ];
        }

        return $map;
    }
}
