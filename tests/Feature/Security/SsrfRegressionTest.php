<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Website;
use App\Services\Monitor\Probe;
use App\Services\Security\SsrfGuard;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SSRF regression suite (SECURITY.md §5, PLAN.md Phase 9 AC-9-01).
 *
 * Exploit-oriented: proves that a blocked destination never reaches the HTTP
 * transport, not merely that validation returned false. No real network calls
 * are made (Http::fake).
 */
final class SsrfRegressionTest extends SecurityTestCase
{
    /**
     * Destinations that must be blocked at validation time, before any request.
     *
     * @return array<string, array{0: string}>
     */
    public static function blockedDestinationProvider(): array
    {
        return [
            'ipv4 loopback' => ['http://127.0.0.1/'],
            'ipv4 loopback alt' => ['http://127.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'rfc1918 10/8' => ['http://10.0.0.1/'],
            'rfc1918 172.16/12' => ['http://172.16.5.4/'],
            'rfc1918 192.168/16' => ['http://192.168.1.1/'],
            'link-local' => ['http://169.254.0.1/'],
            'cloud metadata ip' => ['http://169.254.169.254/latest/meta-data/'],
            'cloud metadata ipv6' => ['http://[fd00:ec2::254]/'],
            'unique-local ipv6' => ['http://[fc00::1]/'],
            'link-local ipv6' => ['http://[fe80::1]/'],
            'unspecified v4' => ['http://0.0.0.0/'],
            'unspecified v6' => ['http://[::]/'],
            'multicast' => ['http://224.0.0.1/'],
            'reserved 240/4' => ['http://240.0.0.1/'],
            'documentation 192.0.2/24' => ['http://192.0.2.10/'],
            'mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'mapped metadata' => ['http://[::ffff:169.254.169.254]/'],
            'localhost name' => ['http://localhost/'],
            'localhost suffix' => ['http://db.localhost/'],
            'internal suffix' => ['http://redis.internal/'],
            'local suffix' => ['http://printer.local/'],
            'bare hostname' => ['http://intranet/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:6379/_'],
            'ftp scheme' => ['ftp://example.com/'],
            'embedded credentials' => ['http://admin:secret@example.com/'],
            'blocked port redis' => ['http://example.com:6379/'],
            'blocked port mysql' => ['http://example.com:3306/'],
            'blocked port ssh' => ['http://example.com:22/'],
            'blocked port smtp' => ['http://example.com:25/'],
        ];
    }

    #[DataProvider('blockedDestinationProvider')]
    public function test_blocked_destination_never_reaches_transport(string $url): void
    {
        Http::fake(); // any real send would be recorded / faked
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);

        $website = Website::factory()->make(['url' => $url, 'follow_redirects' => true]);
        $website->id = 1;

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertFalse($result->success, "SSRF destination {$url} must fail the check");
        Http::assertNothingSent();
    }

    public function test_hostname_resolving_to_private_ip_never_reaches_transport(): void
    {
        Http::fake();
        SsrfGuard::setResolver(fn () => ['127.0.0.1']);

        $website = Website::factory()->make(['url' => 'https://rebind.example.test/']);
        $website->id = 1;

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_mixed_public_private_dns_answer_never_reaches_transport(): void
    {
        Http::fake();
        SsrfGuard::setResolver(fn () => ['93.184.216.34', '127.0.0.1']);

        $website = Website::factory()->make(['url' => 'https://split.example.test/']);
        $website->id = 1;

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_redirect_to_metadata_is_blocked_and_second_hop_never_sent(): void
    {
        Http::fake([
            'https://public.example.test/*' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);
        SsrfGuard::setResolver(fn (string $host) => $host === 'public.example.test'
            ? ['93.184.216.34']
            : ['169.254.169.254']);

        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('SSRF_BLOCKED_REDIRECT', $result->errorType);

        // The first (public) request is legitimate; the blocked redirect must
        // never produce a second request to the private destination.
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_redirect_chain_revalidates_every_hop_against_rebinding(): void
    {
        // Hop 1 public, hop 2 same hostname but now resolves private: the
        // re-validation on hop 2 must reject it (no cross-hop DNS caching).
        $calls = 0;
        SsrfGuard::setResolver(function () use (&$calls) {
            $calls++;

            return $calls <= 1 ? ['93.184.216.34'] : ['10.0.0.5'];
        });

        Http::fake([
            'https://public.example.test/*' => Http::response('', 302, [
                'Location' => 'https://public.example.test/next',
            ]),
        ]);

        $website = Website::factory()->make([
            'url' => 'https://public.example.test/',
            'follow_redirects' => true,
        ]);
        $website->id = 1;

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertFalse($result->success);
        $this->assertSame('SSRF_BLOCKED_REDIRECT', $result->errorType);
        Http::assertSentCount(1);
    }

    public function test_validated_ip_is_the_ip_pinned_for_connection(): void
    {
        // Connection-destination consistency: the Probe must pin the exact
        // validated IP via CURLOPT_RESOLVE and send the original Host header.
        SsrfGuard::setResolver(fn () => ['93.184.216.34']);
        Http::fake([
            'https://public.example.test/*' => Http::response('<title>OK</title>', 200),
        ]);

        $website = Website::factory()->make(['url' => 'https://public.example.test/']);
        $website->id = 1;

        // Capture the Guzzle options the Probe passed to the transport.
        $captured = [];
        Http::fake(function ($request, $options) use (&$captured) {
            $captured = $options;

            return Http::response('<title>OK</title>', 200);
        });

        $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

        $this->assertTrue($result->success);
        $this->assertSame('93.184.216.34', $result->resolvedIp);

        // The socket is pinned to the exact validated IP (CURLOPT_RESOLVE), so a
        // later DNS change cannot redirect the connection elsewhere.
        $this->assertSame(
            ['public.example.test:443:93.184.216.34'],
            $captured['curl'][CURLOPT_RESOLVE] ?? null,
        );

        Http::assertSent(fn ($request) => $request->hasHeader('Host', 'public.example.test'));
    }

    public function test_non_http_schemes_are_rejected_before_transport(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'dict://x/', 'ldap://x/', 'data:text/plain,x'] as $url) {
            Http::fake();
            SsrfGuard::setResolver(fn () => ['93.184.216.34']);
            $website = Website::factory()->make(['url' => $url]);
            $website->id = 1;

            $result = (new Probe(config('sentinel.probe_limits'), app(HttpFactory::class)))->probe($website);

            $this->assertFalse($result->success, "{$url} must be rejected");
        }

        Http::assertNothingSent();
    }
}
