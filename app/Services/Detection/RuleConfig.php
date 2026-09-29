<?php

declare(strict_types=1);

namespace App\Services\Detection;

final class RuleConfig
{
    public const CONFIDENCE_MULTIPLIERS = [
        'low' => 0.5,
        'medium' => 1.0,
        'high' => 1.5,
    ];

    public const CATEGORIES = [
        'availability',
        'ssl',
        'redirect',
        'content-fingerprint',
        'content-keyword',
        'external-link',
        'seo-pattern',
    ];

    public static function confidenceMultiplier(string $confidence): float
    {
        return self::CONFIDENCE_MULTIPLIERS[$confidence] ?? 1.0;
    }
}
