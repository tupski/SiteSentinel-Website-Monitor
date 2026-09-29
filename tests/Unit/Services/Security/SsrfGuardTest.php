<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\SsrfGuard;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SsrfGuardTest extends TestCase
{
    public static function validUrlProvider(): array
    {
        return [
            'public http' => ['http://example.com/'],
            'public https' => ['https://example.com/'],
            'public with path' => ['https://example.com/path?a=1'],
        ];
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'localhost' => ['http://localhost/'],
            '127.0.0.1' => ['http://127.0.0.1/'],
            '10.0.0.1' => ['http://10.0.0.1/'],
            '192.168.1.1' => ['http://192.168.1.1/'],
            '172.16.0.1' => ['http://172.16.0.1/'],
            '169.254.169.254' => ['http://169.254.169.254/'],
            '0.0.0.0' => ['http://0.0.0.0/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 fc00' => ['http://[fc00::1]/'],
            'IPv6 fe80' => ['http://[fe80::1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'blocked port 22' => ['http://example.com:22/'],
            'ftp scheme' => ['ftp://example.com/'],
            'bare hostname' => ['http://foo/'],
            'embedded credentials' => ['http://user:pass@example.com/'],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function test_valid_urls_are_accepted(string $url): void
    {
        SsrfGuard::setResolver(fn () => ['1.2.3.4']);
        $result = SsrfGuard::validate($url);
        $this->assertNotNull($result['host']);
        $this->assertSame('1.2.3.4', $result['selected_ip']);
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_invalid_urls_are_rejected(string $url): void
    {
        SsrfGuard::setResolver(fn () => ['1.2.3.4']);
        $this->expectException(ValidationException::class);
        SsrfGuard::validate($url);
    }

    public function test_dns_rebinding_private_ip_is_blocked(): void
    {
        SsrfGuard::setResolver(fn () => ['127.0.0.1']);
        $this->expectException(ValidationException::class);
        SsrfGuard::validate('https://public.example.test/');
    }

    public function test_public_dns_result_is_allowed(): void
    {
        SsrfGuard::setResolver(fn () => ['1.2.3.4']);
        $result = SsrfGuard::validate('https://public.example.test/');
        $this->assertSame('public.example.test', $result['host']);
        $this->assertSame('1.2.3.4', $result['selected_ip']);
    }

    public function test_ipv4_mapped_ipv6_loopback_is_blocked(): void
    {
        SsrfGuard::setResolver(fn () => ['::ffff:127.0.0.1']);
        $this->expectException(ValidationException::class);
        SsrfGuard::validate('https://mapped.example.test/');
    }

    public function test_multiple_resolved_ips_all_validated(): void
    {
        SsrfGuard::setResolver(fn () => ['1.2.3.4', '1.2.3.5']);
        $result = SsrfGuard::validate('https://multi.example.test/');
        $this->assertSame(['1.2.3.4', '1.2.3.5'], $result['valid_ips']);
    }

    public function test_mixed_resolved_ips_select_only_public(): void
    {
        SsrfGuard::setResolver(fn () => ['1.2.3.4', '127.0.0.1']);
        $result = SsrfGuard::validate('https://mixed.example.test/');
        $this->assertSame('1.2.3.4', $result['selected_ip']);
    }
}
