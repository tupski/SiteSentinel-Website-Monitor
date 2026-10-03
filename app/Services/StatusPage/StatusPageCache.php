<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPageSetting;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

/**
 * Projection cache (STATUS-PAGE.md §9).
 *
 * Caches DTO only. Never raw rows.
 */
final class StatusPageCache
{
    public function __construct(
        private readonly StatusProjector $projector,
    ) {}

    public function remember(StatusPageSetting $settings): PublicStatusDTO
    {
        $ttl = $this->ttl();
        $key = $this->key($settings);

        /** @var PublicStatusDTO $dto */
        $dto = Cache::remember($key, $ttl, fn (): PublicStatusDTO => $this->projector->project());

        return $dto;
    }

    public function invalidate(): void
    {
        try {
            $prefix = (string) config('sentinel.status_page.cache_prefix', 'status:projection:v1');
            $settings = StatusPageSetting::singleton();
            $stamp = $settings->updated_at?->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') ?? 'never';
            foreach (['Private', 'Public', 'Password Protected'] as $mode) {
                Cache::forget($prefix.':'.sha1($mode).':'.$stamp);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function bust(): void
    {
        try {
            app(self::class)->invalidate();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function key(StatusPageSetting $settings): string
    {
        $prefix = (string) config('sentinel.status_page.cache_prefix', 'status:projection:v1');
        $modeHash = sha1((string) $settings->visibility_mode);
        $stamp = $settings->updated_at?->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') ?? 'never';

        return $prefix.':'.$modeHash.':'.$stamp;
    }

    public function ttl(): int
    {
        $floor = max(1, (int) config('sentinel.status_page.ttl_floor', 60));

        $shortest = Website::query()
            ->where('is_active', true)
            ->where('is_visible_on_status', true)
            ->min('check_interval_seconds');

        if ($shortest === null) {
            return $floor;
        }

        return max($floor, (int) $shortest);
    }
}
