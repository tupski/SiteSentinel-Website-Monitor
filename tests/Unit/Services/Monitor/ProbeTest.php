<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Monitor;

use App\Models\Website;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
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

    public function test_oversized_response_is_truncated(): void
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

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->httpStatus);
    }
}
