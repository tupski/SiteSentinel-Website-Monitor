<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Support\PerPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server-side per-page whitelist (ADR-034). Raw input is never trusted:
 * only 10/20/50/100 (and "all") are honoured, anything else falls back to 20.
 */
final class PerPageHelperTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function rawValues(): array
    {
        return [
            'default when missing' => [null, PerPage::DEFAULT],
            'default when empty string' => ['', PerPage::DEFAULT],
            'allowed 10' => ['10', 10],
            'allowed 20' => ['20', 20],
            'allowed 50' => ['50', 50],
            'allowed 100' => ['100', 100],
            'allowed integer 50' => [50, 50],
            'all lower' => ['all', PerPage::ALL],
            'all upper' => ['ALL', PerPage::ALL],
            'all padded' => [' all ', PerPage::ALL],
            'reject 0' => ['0', PerPage::DEFAULT],
            'reject negative' => ['-5', PerPage::DEFAULT],
            'reject out of list 25' => ['25', PerPage::DEFAULT],
            'reject 999' => ['999', PerPage::DEFAULT],
            'reject sql-ish string' => ['10; DROP TABLE users', PerPage::DEFAULT],
            'reject decimal' => ['10.5', PerPage::DEFAULT],
            'reject alpha' => ['lots', PerPage::DEFAULT],
            'reject array' => [['10'], PerPage::DEFAULT],
        ];
    }

    #[DataProvider('rawValues')]
    public function test_from_raw_whitelist(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, PerPage::fromRaw($raw));
    }

    public function test_page_size_maps_all_to_minus_one(): void
    {
        $this->assertSame(20, PerPage::pageSize(20));
        $this->assertSame(-1, PerPage::pageSize(PerPage::ALL));
    }

    public function test_options_include_expected_values_in_order(): void
    {
        $this->assertSame([10, 20, 50, 100, 'all'], PerPage::options());
    }
}
