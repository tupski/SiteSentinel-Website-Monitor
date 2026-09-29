<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\SsrfUrlValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SsrfUrlValidatorTest extends TestCase
{
    public static function validUrlProvider(): array
    {
        return [
            'plain http' => ['http://example.com/', 'http', 'example.com'],
            'plain https' => ['https://example.com/', 'https', 'example.com'],
            'path preserved' => ['https://example.com/path?a=1', 'https', 'example.com'],
            'subdomain' => ['https://www.example.com/', 'https', 'www.example.com'],
            'non-standard-but-allowed-port' => ['https://example.com:8080/', 'https', 'example.com'],
        ];
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com'],
            'file scheme' => ['file:///etc/passwd'],
            'localhost' => ['http://localhost'],
            'localhost with port' => ['http://localhost:3000'],
            'loopback ip' => ['http://127.0.0.1'],
            'loopback ip with port' => ['http://127.0.0.1:8000'],
            'private 10/8' => ['http://10.0.0.1'],
            'private 172.16/12' => ['http://172.16.0.1'],
            'private 192.168/16' => ['http://192.168.1.1'],
            'link-local' => ['http://169.254.169.254'],
            'ipv6 loopback' => ['http://[::1]'],
            'ipv6 private' => ['http://[fc00::1]'],
            'blocked port 25' => ['http://example.com:25'],
            'blocked port 22' => ['http://example.com:22'],
            'embedded credentials' => ['https://user:pass@example.com'],
            'missing scheme' => ['example.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function test_accepts_valid_public_urls(string $url, string $expectedScheme, string $expectedHost): void
    {
        $result = SsrfUrlValidator::validate($url);

        $this->assertSame($expectedScheme, $result['scheme']);
        $this->assertSame($expectedHost, $result['host']);
        $this->assertSame($url, $result['url']);
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_rejects_dangerous_urls(string $url): void
    {
        $this->expectException(ValidationException::class);

        SsrfUrlValidator::validate($url);
    }

    public function test_blocks_url_whose_dns_resolves_to_private_ip(): void
    {
        SsrfUrlValidator::setResolver(fn () => ['127.0.0.1']);

        try {
            $this->expectException(ValidationException::class);

            SsrfUrlValidator::validate('http://localtest.me');
        } finally {
            SsrfUrlValidator::setResolver(null);
        }
    }

    public function test_returns_normalized_url_with_trailing_slash_when_missing(): void
    {
        $result = SsrfUrlValidator::validate('https://example.com');

        $this->assertSame('https://example.com/', $result['url']);
    }
}
