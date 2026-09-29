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
        } catch (ValidationException $e) {
            return $this->failure('SSRF_BLOCKED', $e->getMessage());
        }

        while (true) {
            try {
                $response = $this->performRequest($currentUrl, $bodyLimit);
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

            if ($isRedirect && $website->follow_redirects && $hop < $maxHops) {
                $location = $response->header('Location');
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if ($currentUrl === null) {
                    return $this->failure('REDIRECT_MALFORMED', 'Malformed redirect location.');
                }
                try {
                    $validated = SsrfGuard::validate($currentUrl);
                    $currentUrl = $validated['url'];
                } catch (ValidationException $e) {
                    return $this->failure('SSRF_BLOCKED_REDIRECT', 'Redirect destination is not allowed.');
                }
                $hop++;

                continue;
            }

            $body = $response->body();
            $size = strlen($body);

            return new ProbeResult(
                success: $status >= 200 && $status < 400,
                httpStatus: $status,
                durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
                finalUrl: $currentUrl,
                redirectCount: $hop,
                sslValid: $this->extractSslInfo($currentUrl)['valid'] ?? null,
                sslIssuer: $this->extractSslInfo($currentUrl)['issuer'] ?? null,
                title: $this->extractTitle($body),
                contentHash: hash('sha256', $body),
                responseSizeBytes: $size,
                redirectChain: $chain,
            );
        }
    }

    private function performRequest(string $url, int $bodyLimit): mixed
    {
        return $this->http->withOptions([
            'curl' => [CURLOPT_MAXFILESIZE => $bodyLimit],
        ])->get($url);
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

    private function extractSslInfo(string $url): array
    {
        if (! str_starts_with($url, 'https://')) {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return [];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (! $socket) {
            return [];
        }

        $params = stream_context_get_params($socket);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            fclose($socket);

            return [];
        }

        $parsed = openssl_x509_parse($cert);
        fclose($socket);
        if (! $parsed) {
            return [];
        }

        return [
            'valid' => true,
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
            'expires_at' => date('Y-m-d H:i:s', $parsed['validTo_time_t'] ?? time()),
        ];
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
