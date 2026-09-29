<?php

declare(strict_types=1);

namespace App\Services\Monitor;

final readonly class ProbeResult
{
    public function __construct(
        public bool $success,
        public ?int $httpStatus = null,
        public ?int $durationMs = null,
        public ?string $finalUrl = null,
        public ?string $resolvedIp = null,
        public int $redirectCount = 0,
        public ?bool $sslValid = null,
        public ?string $sslIssuer = null,
        public ?string $sslExpiresAt = null,
        public ?string $title = null,
        public ?string $contentHash = null,
        public ?int $responseSizeBytes = null,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public ?array $redirectChain = null,
        public ?string $body = null,
        public array $headers = [],
        public array $extractedKeywords = [],
        public array $extractedDomains = [],
        public array $suspiciousPatterns = [],
    ) {}
}
