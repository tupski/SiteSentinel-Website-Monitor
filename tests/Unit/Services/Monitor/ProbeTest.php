<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Monitor;

use App\Models\Website;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProbeTest extends TestCase
{
    public function test_safe_public_url_is_probed(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        Http::fake([
            'https://public.example.test/*' => Http::response('<html><head><title>OK</title></head><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('OK', $result->title);
        $this->assertNotNull($result->contentHash);
        $this->assertSame('1.2.3.4', $result->resolvedIp);
    }

    public function test_redirect_to_private_ip_is_blocked(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn (string $host) => $host === 'public.example.test' ? ['1.2.3.4'] : ['127.0.0.1']);

        Http::fake([
            'https://public.example.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:8080/admin']),
        ]);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('SSRF_BLOCKED_REDIRECT', $result->errorType);
    }

    public function test_relative_redirect_is_followed_safely(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/path',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        Http::fake([
            'https://public.example.test/*' => function ($request) {
                return $request->url() === 'https://public.example.test/path'
                    ? Http::response('', 302, ['Location' => '/other'])
                    : Http::response('<title>Other</title>', 200);
            },
        ]);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->httpStatus);
    }

    public function test_redirect_to_blocked_port_is_blocked(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        Http::fake([
            'https://public.example.test/*' => Http::response('', 302, ['Location' => 'http://public.example.test:22/']),
        ]);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('SSRF_BLOCKED_REDIRECT', $result->errorType);
    }

    public function test_redirect_chain_limit_is_enforced(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        Http::fake([
            'https://public.example.test/*' => Http::response('', 302, ['Location' => 'https://public.example.test/']),
        ]);

        $probe = new Probe(array_merge(config('sentinel.probe_limits'), ['redirect_limit' => 2]), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('REDIRECT_LIMIT_EXCEEDED', $result->errorType);
    }

    public function test_oversized_response_is_rejected(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://big.example.test/',
            'follow_redirects' => false,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        Http::fake([
            'https://big.example.test/*' => Http::response(str_repeat('a', 5_000_000), 200),
        ]);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('RESPONSE_TOO_LARGE', $result->errorType);
    }

    public function test_dns_failure_is_recorded(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://failing.example.test/',
            'follow_redirects' => false,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => []);

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('SSRF_BLOCKED', $result->errorType);
    }

    /**
     * Regression: the Probe must only ever pass cURL options Guzzle's handlers
     * accept. A raw CURLOPT_MAXFILESIZE in the "curl" option array is outside
     * Guzzle's built-in allow-list (and the stream handler rejects the whole
     * "curl" array), which made every real check fail with REQUEST_ERROR while
     * Http::fake-driven tests stayed green. Replaying the captured options
     * through the real CurlFactory reproduces the transport-level validation.
     */
    public function test_probe_curl_options_are_accepted_by_guzzle_handler(): void
    {
        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => false,
        ]);
        $website->id = 1;

        SsrfGuard::setResolver(fn () => ['1.2.3.4']);

        $captured = [];
        Http::fake(function ($request, $options) use (&$captured) {
            $captured = $options;

            return Http::response('<title>OK</title>', 200);
        });

        $probe = new Probe(config('sentinel.probe_limits'), app(HttpFactory::class));
        $result = $probe->probe($website);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('curl', $captured);

        // Throws InvalidArgumentException if any option is outside the allow-list.
        (new CurlFactory(1))->create(
            new Request('GET', 'https://public.example.test/'),
            ['curl' => $captured['curl']],
        );

        $this->assertSame(
            ['public.example.test:443:1.2.3.4'],
            $captured['curl'][CURLOPT_RESOLVE] ?? null,
        );
    }
}
