<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Notifications\MessageRedactor;

/**
 * Logging-boundary regression suite (SECURITY.md §4.2, §9).
 *
 * The audit trail and application logs must never carry credentials, and
 * attacker-controlled input must not be able to inject log lines.
 */
final class LoggingBoundariesTest extends SecurityTestCase
{
    public function test_redactor_removes_newline_injection_material(): void
    {
        // An attacker-supplied error string containing CRLF must not be able to
        // forge a new log line carrying a fake credential shape.
        $hostile = "error occurred\npassword=leakedvalue";
        $redacted = (string) MessageRedactor::redact($hostile);

        $this->assertStringNotContainsString('leakedvalue', $redacted);
    }

    public function test_redactor_is_case_insensitive(): void
    {
        foreach (['PASSWORD=abc123secret', 'Token=abc123secret', 'X-API-KEY: abc123secret'] as $input) {
            $this->assertStringNotContainsString('abc123secret', (string) MessageRedactor::redact($input));
        }
    }

    public function test_redactor_bounds_output_length(): void
    {
        $huge = str_repeat('a', 100000);
        $this->assertLessThanOrEqual(2000, strlen((string) MessageRedactor::redact($huge)));
    }

    public function test_redactor_handles_null_and_empty_safely(): void
    {
        $this->assertNull(MessageRedactor::redact(null));
        $this->assertSame('', MessageRedactor::redact(''));
    }

    public function test_smtp_url_credentials_are_redacted(): void
    {
        $input = 'Connection failed for smtp://user:smtp-super-secret@mail.example.test:587';
        $this->assertStringNotContainsString('smtp-super-secret', (string) MessageRedactor::redact($input));
    }
}
