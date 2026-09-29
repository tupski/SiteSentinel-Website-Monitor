<?php

declare(strict_types=1);

namespace App\Services\Detection;

/**
 * Immutable detection signal emitted by a rule.
 */
final readonly class Signal
{
    public function __construct(
        public string $ruleId,
        public string $category,
        public int $weight,
        public string $confidence,
        public string $reason,
        public array $evidence = [],
    ) {}
}
