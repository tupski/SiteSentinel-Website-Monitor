<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SiteSentinel System Configuration
    |--------------------------------------------------------------------------
    |
    | Authoritative configuration values derived from PRD.md, ARCHITECTURE.md,
    | DATABASE.md, DETECTION-RULES.md, NOTIFICATIONS.md, and SECURITY.md.
    | Configuration only — no business logic.
    |
    */

    'monitoring' => [
        // Default check interval in seconds (default 5 minutes, PRD FR-17 / DATABASE websites.check_interval_seconds)
        'default_interval_seconds' => (int) env('SENTINEL_DEFAULT_INTERVAL_SECONDS', 300),

        // Allowed check intervals in seconds (PRD FR-17)
        'allowed_intervals_seconds' => [60, 180, 300, 600, 900, 1800, 3600],

        // Default request timeout in seconds (default 10s, PRD FR-18 / SECURITY §6)
        'default_timeout_seconds' => (int) env('SENTINEL_DEFAULT_TIMEOUT_SECONDS', 10),

        // Allowed request timeout range in seconds (PRD FR-18 / SECURITY §6)
        'min_timeout_seconds' => 3,
        'max_timeout_seconds' => 30,

        // Consecutive failure / success thresholds for availability transitions (PRD FR-53, NOTIFICATIONS §9.3)
        'consecutive_failures_threshold' => (int) env('SENTINEL_CONSECUTIVE_FAILURES_THRESHOLD', 2),
        'consecutive_successes_threshold' => (int) env('SENTINEL_CONSECUTIVE_SUCCESSES_THRESHOLD', 2),

        // Maximum concurrent monitoring queue workers / fan-out (SECURITY §6, ARCHITECTURE §12)
        'max_concurrent_checks' => (int) env('SENTINEL_MAX_CONCURRENT_CHECKS', 10),
    ],

    'probe_limits' => [
        // Connect timeout fixed allowance in seconds (SECURITY §6)
        'connect_timeout_seconds' => (int) env('SENTINEL_CONNECT_TIMEOUT_SECONDS', 5),

        // Total HTTP request timeout ceiling in seconds (SECURITY §6)
        'total_request_timeout_seconds' => (int) env('SENTINEL_TOTAL_REQUEST_TIMEOUT_SECONDS', 15),

        // Per-check wall-clock budget for queue job in seconds (SECURITY §6)
        'job_timeout_seconds' => (int) env('SENTINEL_JOB_TIMEOUT_SECONDS', 30),

        // DNS resolution timeout in seconds (SECURITY §6)
        'dns_timeout_seconds' => (int) env('SENTINEL_DNS_TIMEOUT_SECONDS', 3),

        // Maximum redirect hops allowed before failing with redirect_cap_exceeded (PRD FR-23, SECURITY §5.9)
        'max_redirect_hops' => (int) env('SENTINEL_MAX_REDIRECT_HOPS', 5),

        // Maximum response body size downloaded and inspected in bytes (default 2 MB, PRD FR-38, SECURITY §6)
        'max_response_body_bytes' => (int) env('SENTINEL_MAX_RESPONSE_BODY_BYTES', 2 * 1024 * 1024),

        // Maximum decompressed body size in bytes (default 10 MB, SECURITY §6)
        'max_decompressed_bytes' => (int) env('SENTINEL_MAX_DECOMPRESSED_BYTES', 10 * 1024 * 1024),

        // Maximum redirect chain log size in bytes (SECURITY §6)
        'max_redirect_chain_bytes' => (int) env('SENTINEL_MAX_REDIRECT_CHAIN_BYTES', 256 * 1024),

        // Allowed URL schemes for monitored targets (SECURITY §5.3)
        'allowed_schemes' => ['http', 'https'],

        // Blocked destination ports (SECURITY §5) — applied to initial URL and redirects.
        'blocked_ports' => [22, 25, 3306, 6379],
    ],

    'scoring' => [
        // Thresholds mapping correlated score to security severity bands (PRD §11.2, DETECTION-RULES §6)
        'threshold_info' => (int) env('SENTINEL_SCORING_THRESHOLD_INFO', 1),
        'threshold_warning' => (int) env('SENTINEL_SCORING_THRESHOLD_WARNING', 8),
        'threshold_critical' => (int) env('SENTINEL_SCORING_THRESHOLD_CRITICAL', 15),

        // Correlation guard requirement: minimum distinct rule categories required for CRITICAL escalation (PRD §11.2, ADR-009)
        'correlation_guard_min_categories' => (int) env('SENTINEL_CORRELATION_GUARD_MIN_CATEGORIES', 2),

        // Default cap on points any single category may contribute to total score (DETECTION-RULES §6.2)
        'default_category_cap' => (int) env('SENTINEL_CATEGORY_CAP', 12),
    ],

    /*
    |----------------------------------------------------------------------
    | Detection — suspicious keyword dictionary (DETECTION-RULES §8.6)
    |----------------------------------------------------------------------
    |
    | Tiered by signal strength. These are the code-defined defaults mirrored
    | into detection_rules.config (JSON) for tunability (ADR-008, DATABASE §3.8).
    | Tier-3 terms act as corroborators only and can never escalate past INFO.
    |
    */
    'detection_keywords' => [
        'tier1' => [
            'maxwin', 'rtp slot', 'situs slot', 'togel', 'bandar',
            'gacor', 'judi online', 'link alternatif', 'scatter hitam',
        ],
        'tier2' => [
            'jackpot', 'casino', 'betting', 'slot', 'rtp',
            'deposit', 'withdraw', 'taruhan',
        ],
        'tier3' => [
            'bonus', 'game', 'promo', 'hadiah', 'menang',
        ],

        // Tier-2 density threshold: rate >= max(3 * baseline_rate, this floor) (DETECTION-RULES §8.6)
        'tier2_density_floor' => (float) env('SENTINEL_TIER2_DENSITY_FLOOR', 0.002),

        // Per-website keyword suppression applied before any rule evaluation (FR-47)
        'ignored_global' => [],
    ],

    /*
    |----------------------------------------------------------------------
    | Detection — external link controls (DETECTION-RULES §8.7, §7.2)
    |----------------------------------------------------------------------
    */
    'links' => [
        // Domains never treated as "new" or suspicious for any website
        'ignored_domains_global' => [
            'google.com', 'googleapis.com', 'gstatic.com', 'facebook.com',
            'twitter.com', 'instagram.com', 'youtube.com', 'linkedin.com',
            'wikipedia.org', 'cloudflare.com', 'schema.org', 'w3.org',
        ],

        // TLDs treated as suspicious for redirect targets and outbound links
        'suspicious_tlds' => ['.top', '.xyz', '.click', '.loan', '.bid', '.download'],
    ],

    /*
    |----------------------------------------------------------------------
    | Incidents — lifecycle thresholds (PRD §12, PLAN.md Phase 6)
    |----------------------------------------------------------------------
    */
    'incidents' => [
        // Consecutive healthy checks required before an open incident is
        // auto-resolved (FR-59, AC-11). Configurable per deployment.
        'recovery_consecutive_checks' => (int) env('SENTINEL_RECOVERY_CONSECUTIVE_CHECKS', 2),

        // Availability incident escalates to CRITICAL when the failure persists
        // beyond this many consecutive failures (PRD §11.3).
        'availability_critical_after_failures' => (int) env('SENTINEL_AVAILABILITY_CRITICAL_AFTER_FAILURES', 6),
    ],

    'notifications' => [
        // Global default cooldown window between repeat alerts for website+channel (NOTIFICATIONS §9.2)
        'default_cooldown_minutes' => (int) env('SENTINEL_DEFAULT_COOLDOWN_MINUTES', 15),

        // Retry policy for notification delivery (NOTIFICATIONS §11)
        'max_retries' => 3,
        'retry_backoff_seconds' => [60, 300, 900],
    ],

    'push' => [
        // Web Push (VAPID) keys (NOTIFICATIONS.md §7.3, SECURITY.md §4, ADR-032).
        // The PUBLIC key is exposed to the opt-in JS; the PRIVATE key is a secret
        // and is NEVER logged, rendered, or placed in a payload.
        'vapid_public_key' => (string) env('VAPID_PUBLIC_KEY', ''),
        'vapid_private_key' => (string) env('VAPID_PRIVATE_KEY', ''),
        'vapid_subject' => (string) env('VAPID_SUBJECT', ''),

        // Delivery TTL (seconds) for a push message and default payload icon.
        'ttl_seconds' => (int) env('VAPID_TTL_SECONDS', 3600),
    ],

    'retention' => [
        // Check telemetry retention window in days (default 30, options 30/60/90, PRD FR-91, ADR-016)
        'checks_days' => (int) env('SENTINEL_RETENTION_CHECKS_DAYS', 30),
        'checks_allowed_days' => [30, 60, 90],

        // Incident records retention in days (PRD §16, ADR-016)
        'incidents_days' => (int) env('SENTINEL_RETENTION_INCIDENTS_DAYS', 365),

        // Notification delivery logs retention in days (PRD §16, ADR-016)
        'notification_logs_days' => (int) env('SENTINEL_RETENTION_NOTIFICATION_LOGS_DAYS', 90),

        // Forensic HTML/header snapshots retention in days (PRD §16, ADR-016)
        'snapshots_days' => (int) env('SENTINEL_RETENTION_SNAPSHOTS_DAYS', 14),
    ],

    'auth' => [
        // Login throttling parameters (SECURITY §2.4, PRD FR-06)
        'max_login_attempts' => (int) env('SENTINEL_MAX_LOGIN_ATTEMPTS', 5),

        // Soft-limit window in minutes: max_login_attempts failures within
        // this window are throttled with HTTP 429 (SECURITY §2.4)
        'soft_limit_window_minutes' => (int) env('SENTINEL_SOFT_LIMIT_WINDOW_MINUTES', 15),

        // Lockout: after this many failures within lockout_window_minutes,
        // the identity is locked for lockout_duration_minutes (SECURITY §2.4)
        'lockout_threshold' => (int) env('SENTINEL_LOCKOUT_THRESHOLD', 10),
        'lockout_window_minutes' => (int) env('SENTINEL_LOCKOUT_WINDOW_MINUTES', 30),
        'lockout_duration_minutes' => (int) env('SENTINEL_LOCKOUT_DURATION_MINUTES', 15),

        // Minimum password length in characters (SECURITY §2.3)
        'min_password_length' => (int) env('SENTINEL_MIN_PASSWORD_LENGTH', 12),

        // Session timeout parameters in minutes (SECURITY §3.4)
        'idle_timeout_minutes' => (int) env('SENTINEL_IDLE_TIMEOUT_MINUTES', 30),
        'absolute_timeout_minutes' => (int) env('SENTINEL_ABSOLUTE_TIMEOUT_MINUTES', 480),
    ],

    'status_page' => [
        'ttl_floor' => (int) env('SENTINEL_STATUS_TTL_FLOOR', 60),
        'stale_multiplier' => (int) env('SENTINEL_STATUS_STALE_MULTIPLIER', 2),
        'stale_floor' => (int) env('SENTINEL_STATUS_STALE_FLOOR', 300),
        'unlock_max' => (int) env('SENTINEL_STATUS_UNLOCK_MAX', 5),
        'unlock_window' => (int) env('SENTINEL_STATUS_UNLOCK_WINDOW', 10),
        'history_enabled' => (bool) env('SENTINEL_STATUS_HISTORY_ENABLED', false),
        'band_normal' => (int) env('SENTINEL_STATUS_BAND_NORMAL', 800),
        'band_slow' => (int) env('SENTINEL_STATUS_BAND_SLOW', 2500),
        'cache_prefix' => (string) env('SENTINEL_STATUS_CACHE_PREFIX', 'status:projection:v1'),
    ],
];
