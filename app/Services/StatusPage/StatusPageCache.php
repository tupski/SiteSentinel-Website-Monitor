<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Facades\Cache;

/**
 * Per-page projection cache (STATUS-PAGE.md §9, ADR-031).
 *
 * Caches DTO only. Never raw rows. The cache key includes the page id and slug
 * (via a hash), so one page can never serve another page's projection.
 *
 * Invalidation is a global epoch bump: the epoch participates in every key, so
 * a single increment supersedes all cached projections across all pages in one
 * step (there is no reliable way to enumerate cache keys without tags).
 */
final class StatusPageCache
{
    public function __construct(
        private readonly StatusProjector $projector,
    ) {}

    public function remember(StatusPage $page): PublicStatusDTO
    {
        $ttl = $this->ttl($page);
        $key = $this->key($page);

        // Store the DTO's plain allowlist array, never the object. Laravel's
        // `cache.serializable_classes` defaults to false (gadget-chain defense),
        // so a serializing store (database/file/redis) would hand back a
        // `__PHP_Incomplete_Class` for a cached object. The array form survives
        // any store and is still "DTO only, never raw rows" (STATUS-PAGE.md §9).
        /** @var array{banner: string, services: array<int, mixed>, updatedDayBucket: string} $payload */
        $payload = Cache::remember($key, $ttl, fn (): array => $this->projector->project($page)->toArray());

        return PublicStatusDTO::fromArray($payload);
    }

    public function invalidate(?StatusPage $page = null): void
    {
        try {
            $this->bumpEpoch();

            if ($page !== null) {
                $page->refresh();
                Cache::forget($this->key($page));
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

    public function key(?StatusPage $page = null): string
    {
        $page ??= StatusPage::resolveDefault();

        $prefix = (string) config('sentinel.status_page.cache_prefix', 'status:projection:v1');
        $slugHash = sha1((string) $page->slug);
        $stamp = $page->updated_at?->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') ?? 'never';

        return $prefix.':'.$slugHash.':'.$page->id.':'.$stamp.':'.$this->epoch();
    }

    public function ttl(?StatusPage $page = null): int
    {
        $page ??= StatusPage::resolveDefault();

        $floor = max(1, (int) config('sentinel.status_page.ttl_floor', 60));

        $shortest = $this->projector->publishedQuery($page)->min('check_interval_seconds');

        if ($shortest === null) {
            return $floor;
        }

        return max($floor, (int) $shortest);
    }

    private function epoch(): int
    {
        return max(1, (int) Cache::get($this->epochKey(), 1));
    }

    private function bumpEpoch(): void
    {
        $key = $this->epochKey();
        Cache::forever($key, max(1, (int) Cache::get($key, 1)) + 1);
    }

    private function epochKey(): string
    {
        $prefix = (string) config('sentinel.status_page.cache_prefix', 'status:projection:v1');

        return $prefix.':epoch';
    }
}
