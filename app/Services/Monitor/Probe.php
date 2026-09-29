<?php

declare(strict_types=1);

namespace App\Services\Monitor;

use App\Models\Website;
use App\Services\Security\SsrfGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Validation\ValidationException;
use Throwable;

final class Probe
{
    private PendingRequest $http;

    public function __construct(
        private array $config = [],
        ?HttpFactory $httpFactory = null,
    ) {
        $this->http = ($httpFactory ?? app(HttpFactory::class))
            ->timeout($this->config['request_timeout'] ?? 30)
            ->connectTimeout($this->config['connect_timeout'] ?? 10)
            ->withUserAgent($this->config['user_agent'] ?? 'SiteSentinel/1.0 (+https://example.com)')
            ->withOptions([
                'verify' => true,
                'allow_redirects' => false,
            ]);
    }

    public function probe(Website $website): ProbeResult
    {
        $start = hrtime(true);
        $url = $website->url;
        $hop = 0;
        $maxHops = (int) ($this->config['redirect_limit'] ?? 5);
        $bodyLimit = (int) ($this->config['response_body_limit'] ?? 2 * 1024 * 1024);
        $chain = [];

        try {
            $validated = SsrfGuard::validate($url);
            $currentUrl = $validated['url'];
            $currentIp = $validated['selected_ip'];
        } catch (ValidationException $e) {
            return $this->failure('SSRF_BLOCKED', $e->getMessage());
        }

        while (true) {
            try {
                $response = $this->performRequest($currentUrl, $currentIp, $bodyLimit);
            } catch (Throwable $e) {
                return $this->classifyError($e, $chain, $currentUrl, $start);
            }

            $status = $response->getStatusCode();
            $isRedirect = $status >= 300 && $status < 400 && $response->hasHeader('Location');

            $chain[] = [
                'url' => $currentUrl,
                'status' => $status,
                'is_redirect' => $isRedirect,
            ];

            if ($isRedirect && $website->follow_redirects) {
                if ($hop >= $maxHops) {
                    return $this->failure('REDIRECT_LIMIT_EXCEEDED', 'Maximum redirect hops exceeded.');
                }

                $location = $response->header('Location');
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if ($currentUrl === null) {
                    return $this->failure('REDIRECT_MALFORMED', 'Malformed redirect location.');
                }
                try {
                    $validated = SsrfGuard::validate($currentUrl);
                    $currentUrl = $validated['url'];
                    $currentIp = $validated['selected_ip'];
                } catch (ValidationException $e) {
                    return $this->failure('SSRF_BLOCKED_REDIRECT', 'Redirect destination is not allowed.');
                }
                $hop++;

                continue;
            }

            $body = $this->boundedBody($response, $bodyLimit);
            if ($body === null) {
                return $this->failure('RESPONSE_TOO_LARGE', 'Response body exceeded limit.');
            }
            $size = strlen($body);

            return new ProbeResult(
                success: $status >= 200 && $status < 400,
                httpStatus: $status,
                durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
                finalUrl: $currentUrl,
                resolvedIp: $currentIp,
                redirectCount: $hop,
                sslValid: $this->extractSslInfo($response, $currentUrl)['valid'] ?? null,
                sslIssuer: $this->extractSslInfo($response, $currentUrl)['issuer'] ?? null,
                title: $this->extractTitle($body),
                contentHash: hash('sha256', $body),
                responseSizeBytes: $size,
                redirectChain: $chain,
            );
        }
    }

    private function performRequest(string $url, string $ip, int $bodyLimit): mixed
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? (str_starts_with($url, 'https') ? 443 : 80);

        return $this->http
            ->withOptions([
                'curl' => [
                    CURLOPT_MAXFILESIZE => $bodyLimit,
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                ],
            ])
            ->withHeaders([
                'Host' => $host,
            ])
            ->get($url);
    }

    private function resolveRedirectUrl(string $base, string $location): ?string
    {
        $baseUri = new Uri($base);
        $locUri = new Uri($location);
        $resolved = UriResolver::resolve($baseUri, $locUri);
        $resolvedStr = (string) $resolved;
        if ($resolvedStr === '') {
            return null;
        }

        return $resolvedStr;
    }

    private function extractTitle(string $body): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $body, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: null;
        }

        return null;
    }

    private function extractSslInfo(mixed $response, string $url): array
    {
        if (! str_starts_with($url, 'https://')) {
            return [];
        }

        $cert = $response->handlerStats()['ssl_cert'] ?? null;
        if (! $cert) {
            return [];
        }

        $parsed = openssl_x509_parse($cert);
        if (! $parsed) {
            return [];
        }

        return [
            'valid' => true,
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
            'expires_at' => date('Y-m-d H:i:s', $parsed['validTo_time_t'] ?? time()),
        ];
    }

    private function boundedBody(mixed $response, int $limit): ?string
    {
        $body = $response->body();
        if (strlen($body) > $limit) {
            return null;
        }

        return $body;
    }

    private function failure(string $type, string $message): ProbeResult
    {
        return new ProbeResult(
            success: false,
            errorType: $type,
            errorMessage: $message,
        );
    }

    private function classifyError(Throwable $e, array $chain, string $url, int|float $start): ProbeResult
    {
        $message = $e->getMessage();
        if (str_contains($message, 'cURL error 28')) {
            return $this->failure('TIMEOUT', 'Request timed out.');
        }
        if (str_contains($message, 'cURL error 6')) {
            return $this->failure('DNS_FAILURE', 'Could not resolve hostname.');
        }
        if (str_contains($message, 'cURL error 7')) {
            return $this->failure('CONNECTION_FAILURE', 'Connection failed.');
        }
        if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
            return $this->failure('TLS_FAILURE', 'TLS/SSL error.');
        }
        if (str_contains($message, 'Maximum response size')) {
            return $this->failure('RESPONSE_TOO_LARGE', 'Response body exceeded limit.');
        }

        return $this->failure('REQUEST_ERROR', $message);
    }
}
