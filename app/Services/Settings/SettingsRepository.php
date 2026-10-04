<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cached, typed access to system settings (ADR-035).
 *
 * Responsibilities:
 *  - owns the key registry (group, type, default resolver, validation bounds);
 *  - resolves a missing row to its config-derived default so a read never
 *    breaks when the table or a key is absent;
 *  - caches the whole key/value map behind a version counter that is bumped on
 *    every write, so invalidation is O(1) and works on the `array` store used
 *    by tests (which has no tag support).
 *
 * Secret boundary: only presentational/identity keys are registered here.
 * Infrastructure secrets (APP_KEY, DB, SMTP, API, queue credentials) live in
 * `.env`/config and must never be added to this registry (ADR-035).
 */
final class SettingsRepository
{
    public const SITE_NAME = 'site_name';

    public const SITE_DESCRIPTION = 'site_description';

    public const SITE_LOGO = 'site_logo';

    public const FAVICON = 'favicon';

    public const TIMEZONE = 'timezone';

    private const CACHE_PREFIX = 'sentinel:settings';

    /**
     * In-request memo of the resolved map so repeated reads cost one cache hit.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * The key registry. Every setting the app may read/write is declared here.
     *
     * @return array<string, array{group: string, type: string, default: callable(): mixed}>
     */
    public static function definitions(): array
    {
        return [
            self::SITE_NAME => [
                'group' => 'general',
                'type' => 'string',
                'default' => static fn (): string => (string) config('app.name', 'SiteSentinel'),
            ],
            self::SITE_DESCRIPTION => [
                'group' => 'general',
                'type' => 'string',
                'default' => static fn (): string => '',
            ],
            self::SITE_LOGO => [
                'group' => 'branding',
                'type' => 'string',
                'default' => static fn (): ?string => null,
            ],
            self::FAVICON => [
                'group' => 'branding',
                'type' => 'string',
                'default' => static fn (): ?string => null,
            ],
            self::TIMEZONE => [
                'group' => 'system',
                'type' => 'string',
                'default' => static fn (): string => (string) config('app.timezone', 'UTC'),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /**
     * All settings as a typed key => value map (defaults applied).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = $this->storedValues();
        $resolved = [];

        foreach (self::definitions() as $key => $definition) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? $this->cast($definition['type'], $stored[$key])
                : ($definition['default'])();
        }

        return $this->memo = $resolved;
    }

    /**
     * A single setting value, falling back to its default when unset.
     */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        if (self::isKnown($key)) {
            return (self::definitions()[$key]['default'])();
        }

        return $fallback;
    }

    public function string(string $key, string $fallback = ''): string
    {
        $value = $this->get($key);

        return $value === null ? $fallback : (string) $value;
    }

    public function bool(string $key, bool $fallback = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $fallback : (bool) $value;
    }

    public function int(string $key, int $fallback = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $fallback : (int) $value;
    }

    /**
     * Persist a single setting. Unknown keys are rejected — the registry is the
     * only write surface, so arbitrary (e.g. secret-looking) keys cannot land.
     */
    public function set(string $key, mixed $value): void
    {
        if (! self::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown setting key [{$key}].");
        }

        $definition = self::definitions()[$key];
        $normalized = $this->normalize($definition['type'], $value);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $normalized, 'is_encrypted' => false],
        );

        $this->flush();
    }

    /**
     * Persist several settings at once. Unknown keys are rejected before any
     * write, so a partially-applied payload cannot occur.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (! self::isKnown($key)) {
                throw new \InvalidArgumentException("Unknown setting key [{$key}].");
            }
        }

        foreach ($values as $key => $value) {
            $definition = self::definitions()[$key];
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->normalize($definition['type'], $value), 'is_encrypted' => false],
            );
        }

        $this->flush();
    }

    /**
     * Remove a stored value so its default applies again.
     */
    public function forget(string $key): void
    {
        if (Schema::hasTable('settings')) {
            Setting::query()->where('key', $key)->delete();
        }

        $this->flush();
    }

    /**
     * Clear the in-request memo and bump the cache version (invalidates the
     * cached map on every store, including the tagless `array` test store).
     */
    public function flush(): void
    {
        $this->memo = null;

        try {
            Cache::forever($this->versionKey(), $this->version() + 1);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Raw stored values as key => string. Returns an empty map when the table
     * is absent (e.g. a pre-migration boot) so callers fall back to defaults.
     *
     * @return array<string, string|null>
     */
    private function storedValues(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            /** @var array<string, string|null> $values */
            $values = Cache::remember(
                $this->cacheKey(),
                now()->addHour(),
                static fn (): array => DB::table('settings')
                    ->select(['key', 'value'])
                    ->get()
                    ->mapWithKeys(static fn (object $row): array => [(string) $row->key => $row->value])
                    ->all(),
            );

            return $values;
        } catch (\Throwable $e) {
            // A settings read must never break the app.
            report($e);

            return [];
        }
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => is_string($value) && $value !== '' ? json_decode($value, true) : null,
            default => $value === null ? null : (string) $value,
        };
    }

    private function normalize(string $type, mixed $value): ?string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int', 'float' => $value === null ? null : (string) $value,
            'json' => $value === null ? null : json_encode($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private function version(): int
    {
        try {
            return max(1, (int) Cache::get($this->versionKey(), 1));
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function versionKey(): string
    {
        return self::CACHE_PREFIX.':version';
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX.':map:v'.$this->version();
    }
}
