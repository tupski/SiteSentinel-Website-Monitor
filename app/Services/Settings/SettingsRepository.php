<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cached, typed access to system settings (ADR-035, ADR-043).
 *
 * Responsibilities:
 *  - owns the key registry (group, type, default resolver, validation bounds);
 *  - resolves a missing row to its config-derived default so a read never
 *    breaks when the table or a key is absent;
 *  - caches the whole key/value map behind a version counter that is bumped on
 *    every write, so invalidation is O(1) and works on the `array` store used
 *    by tests (which has no tag support).
 *
 * Every runtime consumer reads through this class (directly or via the
 * `settings()` helper) so an admin edit is actually applied. Consumers that
 * previously read `config('sentinel.*')` directly now read the stored value
 * with the same config value as the fallback default — a stored row wins, an
 * absent row degrades to the config default (no behaviour change on a fresh
 * install).
 *
 * Secret boundary: only presentational/identity and operational tuning keys are
 * registered here. Infrastructure secrets (APP_KEY, DB, SMTP, API, queue
 * credentials, VAPID private key) live in `.env`/config and must never be added
 * to this registry (ADR-035).
 */
final class SettingsRepository
{
    // --- Presentation / identity ---
    public const SITE_NAME = 'site_name';

    public const SITE_DESCRIPTION = 'site_description';

    public const SITE_LOGO = 'site_logo';

    public const FAVICON = 'favicon';

    public const TIMEZONE = 'timezone';

    // --- Monitoring / checks ---
    public const MONITORING_DEFAULT_INTERVAL = 'monitoring.default_interval_seconds';

    public const MONITORING_DEFAULT_TIMEOUT = 'monitoring.default_timeout_seconds';

    public const MONITORING_CONSECUTIVE_FAILURES = 'monitoring.consecutive_failures_threshold';

    public const INCIDENTS_CRITICAL_AFTER_FAILURES = 'incidents.availability_critical_after_failures';

    public const INCIDENTS_RECOVERY_CHECKS = 'incidents.recovery_consecutive_checks';

    // --- Detection / scoring ---
    public const SCORING_THRESHOLD_INFO = 'scoring.threshold_info';

    public const SCORING_THRESHOLD_WARNING = 'scoring.threshold_warning';

    public const SCORING_THRESHOLD_CRITICAL = 'scoring.threshold_critical';

    public const SCORING_CORRELATION_GUARD = 'scoring.correlation_guard_min_categories';

    // --- Notifications ---
    public const NOTIFICATIONS_COOLDOWN_MINUTES = 'notifications.default_cooldown_minutes';

    // --- Retention / data ---
    public const RETENTION_CHECKS_DAYS = 'retention.checks_days';

    public const RETENTION_INCIDENTS_DAYS = 'retention.incidents_days';

    public const RETENTION_NOTIFICATION_LOGS_DAYS = 'retention.notification_logs_days';

    public const RETENTION_SNAPSHOTS_DAYS = 'retention.snapshots_days';

    // --- Security / auth ---
    public const AUTH_MAX_LOGIN_ATTEMPTS = 'auth.max_login_attempts';

    public const AUTH_SOFT_LIMIT_WINDOW = 'auth.soft_limit_window_minutes';

    public const AUTH_LOCKOUT_THRESHOLD = 'auth.lockout_threshold';

    public const AUTH_LOCKOUT_WINDOW = 'auth.lockout_window_minutes';

    public const AUTH_LOCKOUT_DURATION = 'auth.lockout_duration_minutes';

    public const AUTH_MIN_PASSWORD_LENGTH = 'auth.min_password_length';

    public const AUTH_IDLE_TIMEOUT = 'auth.idle_timeout_minutes';

    public const AUTH_ABSOLUTE_TIMEOUT = 'auth.absolute_timeout_minutes';

    private const CACHE_PREFIX = 'sentinel:settings';

    /**
     * In-request memo of the resolved map so repeated reads cost one cache hit.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * Cache version the memo was resolved against. Long-lived processes (queue
     * workers, `schedule:work`) reuse the singleton across jobs, so the memo is
     * revalidated against the version counter: an edit made in a web request is
     * visible to the next job without a worker restart.
     */
    private int $memoVersion = 0;

    /**
     * The key registry. Every setting the app may read/write is declared here.
     *
     * Each entry carries UI metadata (`group`, `field`, `label`, `help`) and the
     * validation bounds (`rules`) so the registry — not the schema — is the
     * single source of truth for the admin surface (ADR-035, ADR-043).
     *
     * @return array<string, array{group: string, type: string, field: string, label: string, help: string, rules: list<string>, default: callable(): mixed}>
     */
    public static function definitions(): array
    {
        return [
            self::SITE_NAME => [
                'group' => 'general',
                'type' => 'string',
                'field' => 'site_name',
                'label' => 'Site name',
                'help' => 'The application name shown in the header, page titles and alert subjects. Maximum 255 characters.',
                'rules' => ['required', 'string', 'max:255'],
                'default' => static fn (): string => (string) config('app.name', 'SiteSentinel'),
            ],
            self::SITE_DESCRIPTION => [
                'group' => 'general',
                'type' => 'string',
                'field' => 'site_description',
                'label' => 'Site description',
                'help' => 'An optional short tagline used as the meta description. Maximum 500 characters.',
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => static fn (): string => '',
            ],
            self::SITE_LOGO => [
                'group' => 'branding',
                'type' => 'string',
                'field' => 'site_logo',
                'label' => 'Logo',
                'help' => 'The logo shown in the header. PNG, JPG, SVG or WebP, maximum 2 MB. Uploading replaces the current logo.',
                'rules' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp', 'max:2048'],
                'default' => static fn (): ?string => null,
            ],
            self::FAVICON => [
                'group' => 'branding',
                'type' => 'string',
                'field' => 'favicon',
                'label' => 'Favicon',
                'help' => 'The browser tab icon. ICO, PNG or SVG, maximum 256 KB. Uploading replaces the current favicon.',
                'rules' => ['nullable', 'file', 'mimes:ico,png,svg', 'mimetypes:image/vnd.microsoft.icon,image/x-icon,image/png,image/svg+xml', 'max:256'],
                'default' => static fn (): ?string => null,
            ],
            self::TIMEZONE => [
                'group' => 'system',
                'type' => 'string',
                'field' => 'timezone',
                'label' => 'Timezone',
                'help' => 'The timezone applied to every timestamp shown in the app. Must be a valid PHP timezone identifier.',
                'rules' => ['required', 'string'],
                'default' => static fn (): string => (string) config('app.timezone', 'UTC'),
            ],

            // --- Monitoring / checks ---
            self::MONITORING_DEFAULT_INTERVAL => [
                'group' => 'monitoring',
                'type' => 'int',
                'field' => 'monitoring_default_interval_seconds',
                'label' => 'Default check interval (seconds)',
                'help' => 'Pre-filled interval when adding a website. Between 30 and 86400 seconds.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:30', 'max:86400'],
                'default' => static fn (): int => (int) config('sentinel.monitoring.default_interval_seconds', 300),
            ],
            self::MONITORING_DEFAULT_TIMEOUT => [
                'group' => 'monitoring',
                'type' => 'int',
                'field' => 'monitoring_default_timeout_seconds',
                'label' => 'Default request timeout (seconds)',
                'help' => 'Pre-filled request timeout when adding a website. Between 3 and 30 seconds.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:3', 'max:30'],
                'default' => static fn (): int => (int) config('sentinel.monitoring.default_timeout_seconds', 10),
            ],
            self::MONITORING_CONSECUTIVE_FAILURES => [
                'group' => 'monitoring',
                'type' => 'int',
                'field' => 'monitoring_consecutive_failures_threshold',
                'label' => 'Consecutive failures before DOWN',
                'help' => 'How many consecutive failed checks before a website is treated as DOWN. Between 1 and 20.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
                'default' => static fn (): int => (int) config('sentinel.monitoring.consecutive_failures_threshold', 2),
            ],
            self::INCIDENTS_CRITICAL_AFTER_FAILURES => [
                'group' => 'monitoring',
                'type' => 'int',
                'field' => 'incidents_availability_critical_after_failures',
                'label' => 'Escalate availability to CRITICAL after',
                'help' => 'Consecutive failures before an availability incident escalates to CRITICAL. Between 1 and 100.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'default' => static fn (): int => (int) config('sentinel.incidents.availability_critical_after_failures', 6),
            ],
            self::INCIDENTS_RECOVERY_CHECKS => [
                'group' => 'monitoring',
                'type' => 'int',
                'field' => 'incidents_recovery_consecutive_checks',
                'label' => 'Healthy checks to auto-resolve',
                'help' => 'Consecutive healthy checks required before an open incident auto-resolves. Between 1 and 20.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
                'default' => static fn (): int => (int) config('sentinel.incidents.recovery_consecutive_checks', 2),
            ],

            // --- Detection / scoring ---
            self::SCORING_THRESHOLD_INFO => [
                'group' => 'detection',
                'type' => 'int',
                'field' => 'scoring_threshold_info',
                'label' => 'INFO threshold',
                'help' => 'Minimum score for the INFO band.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'default' => static fn (): int => (int) config('sentinel.scoring.threshold_info', 1),
            ],
            self::SCORING_THRESHOLD_WARNING => [
                'group' => 'detection',
                'type' => 'int',
                'field' => 'scoring_threshold_warning',
                'label' => 'WARNING threshold',
                'help' => 'Minimum score for the WARNING band. Must be greater than the INFO threshold.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'default' => static fn (): int => (int) config('sentinel.scoring.threshold_warning', 8),
            ],
            self::SCORING_THRESHOLD_CRITICAL => [
                'group' => 'detection',
                'type' => 'int',
                'field' => 'scoring_threshold_critical',
                'label' => 'CRITICAL threshold',
                'help' => 'Minimum score for the CRITICAL band. Must be greater than the WARNING threshold.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
                'default' => static fn (): int => (int) config('sentinel.scoring.threshold_critical', 15),
            ],
            self::SCORING_CORRELATION_GUARD => [
                'group' => 'detection',
                'type' => 'int',
                'field' => 'scoring_correlation_guard_min_categories',
                'label' => 'Correlation guard (min categories)',
                'help' => 'Distinct rule categories required before a CRITICAL incident may open (ADR-009). Between 1 and 7.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:7'],
                'default' => static fn (): int => (int) config('sentinel.scoring.correlation_guard_min_categories', 2),
            ],

            // --- Notifications ---
            self::NOTIFICATIONS_COOLDOWN_MINUTES => [
                'group' => 'notifications',
                'type' => 'int',
                'field' => 'notifications_default_cooldown_minutes',
                'label' => 'Default alert cooldown (minutes)',
                'help' => 'Suppression window between repeat alerts for the same website and channel. Between 1 and 1440.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'default' => static fn (): int => (int) config('sentinel.notifications.default_cooldown_minutes', 15),
            ],

            // --- Retention / data ---
            self::RETENTION_CHECKS_DAYS => [
                'group' => 'retention',
                'type' => 'int',
                'field' => 'retention_checks_days',
                'label' => 'Check telemetry retention (days)',
                'help' => 'How long check results are kept before pruning. One of 30, 60 or 90 days.',
                'rules' => ['sometimes', 'nullable', 'integer', 'in:30,60,90'],
                'default' => static fn (): int => (int) config('sentinel.retention.checks_days', 30),
            ],
            self::RETENTION_INCIDENTS_DAYS => [
                'group' => 'retention',
                'type' => 'int',
                'field' => 'retention_incidents_days',
                'label' => 'Incident retention (days)',
                'help' => 'How long incident history is kept before pruning.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
                'default' => static fn (): int => (int) config('sentinel.retention.incidents_days', 365),
            ],
            self::RETENTION_NOTIFICATION_LOGS_DAYS => [
                'group' => 'retention',
                'type' => 'int',
                'field' => 'retention_notification_logs_days',
                'label' => 'Notification log retention (days)',
                'help' => 'How long delivery logs are kept before pruning.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
                'default' => static fn (): int => (int) config('sentinel.retention.notification_logs_days', 90),
            ],
            self::RETENTION_SNAPSHOTS_DAYS => [
                'group' => 'retention',
                'type' => 'int',
                'field' => 'retention_snapshots_days',
                'label' => 'Evidence snapshot retention (days)',
                'help' => 'How long captured HTML/header snapshots are kept before pruning.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
                'default' => static fn (): int => (int) config('sentinel.retention.snapshots_days', 14),
            ],

            // --- Security / auth ---
            self::AUTH_MAX_LOGIN_ATTEMPTS => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_max_login_attempts',
                'label' => 'Login soft-limit attempts',
                'help' => 'Failed logins allowed inside the soft-limit window before throttling (HTTP 429).',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'default' => static fn (): int => (int) config('sentinel.auth.max_login_attempts', 5),
            ],
            self::AUTH_SOFT_LIMIT_WINDOW => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_soft_limit_window_minutes',
                'label' => 'Soft-limit window (minutes)',
                'help' => 'Rolling window for the login soft limit.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'default' => static fn (): int => (int) config('sentinel.auth.soft_limit_window_minutes', 15),
            ],
            self::AUTH_LOCKOUT_THRESHOLD => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_lockout_threshold',
                'label' => 'Lockout threshold',
                'help' => 'Failed logins before the identity is locked out.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
                'default' => static fn (): int => (int) config('sentinel.auth.lockout_threshold', 10),
            ],
            self::AUTH_LOCKOUT_WINDOW => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_lockout_window_minutes',
                'label' => 'Lockout window (minutes)',
                'help' => 'Rolling window in which failures count toward the lockout threshold.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'default' => static fn (): int => (int) config('sentinel.auth.lockout_window_minutes', 30),
            ],
            self::AUTH_LOCKOUT_DURATION => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_lockout_duration_minutes',
                'label' => 'Lockout duration (minutes)',
                'help' => 'How long a locked-out identity must wait before retrying.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'default' => static fn (): int => (int) config('sentinel.auth.lockout_duration_minutes', 15),
            ],
            self::AUTH_MIN_PASSWORD_LENGTH => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_min_password_length',
                'label' => 'Minimum password length',
                'help' => 'Minimum characters for admin passwords. Never lower than 12 (SECURITY.md §2.3).',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:12', 'max:128'],
                'default' => static fn (): int => (int) config('sentinel.auth.min_password_length', 12),
            ],
            self::AUTH_IDLE_TIMEOUT => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_idle_timeout_minutes',
                'label' => 'Idle session timeout (minutes)',
                'help' => 'Inactivity window before an admin session expires.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
                'default' => static fn (): int => (int) config('sentinel.auth.idle_timeout_minutes', 30),
            ],
            self::AUTH_ABSOLUTE_TIMEOUT => [
                'group' => 'security',
                'type' => 'int',
                'field' => 'auth_absolute_timeout_minutes',
                'label' => 'Absolute session timeout (minutes)',
                'help' => 'Maximum session lifetime regardless of activity.',
                'rules' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
                'default' => static fn (): int => (int) config('sentinel.auth.absolute_timeout_minutes', 480),
            ],
        ];
    }

    /**
     * Ordered group metadata for the admin UI.
     *
     * @return array<string, array{label: string, subtitle: string}>
     */
    public static function groups(): array
    {
        return [
            'general' => ['label' => 'General', 'subtitle' => 'The name and description shown across the app.'],
            'branding' => ['label' => 'Branding', 'subtitle' => 'Logo and favicon. Uploads replace the current file.'],
            'system' => ['label' => 'System', 'subtitle' => 'Deployment-wide display preferences.'],
            'monitoring' => ['label' => 'Monitoring & checks', 'subtitle' => 'Cadence and availability thresholds.'],
            'detection' => ['label' => 'Detection & scoring', 'subtitle' => 'Score bands and the correlation guard.'],
            'notifications' => ['label' => 'Notifications', 'subtitle' => 'Alert suppression behaviour.'],
            'retention' => ['label' => 'Retention & data', 'subtitle' => 'How long telemetry and evidence are kept.'],
            'security' => ['label' => 'Security', 'subtitle' => 'Authentication and session hardening.'],
        ];
    }

    /**
     * Registry entries grouped in UI order.
     *
     * @return array<string, array<string, array{group: string, type: string, field: string, label: string, help: string, rules: list<string>, default: callable(): mixed}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::definitions() as $key => $definition) {
            $grouped[$definition['group']][$key] = $definition;
        }

        return $grouped;
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
     * The config-derived default for a key (used by pull/reconcile).
     */
    public static function defaultFor(string $key): mixed
    {
        return (self::definitions()[$key]['default'])();
    }

    /**
     * All settings as a typed key => value map (defaults applied).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $version = $this->version();

        if ($this->memo !== null && $this->memoVersion === $version) {
            return $this->memo;
        }

        $stored = $this->storedValues();
        $resolved = [];

        foreach (self::definitions() as $key => $definition) {
            $resolved[$key] = (array_key_exists($key, $stored) && $stored[$key] !== null)
                ? $this->cast($definition['type'], $stored[$key])
                : ($definition['default'])();
        }

        $this->memoVersion = $version;

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
        $this->memoVersion = 0;

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
