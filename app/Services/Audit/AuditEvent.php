<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Canonical audit event-name catalogue (SECURITY.md §9.1).
 *
 * This class is the single source of truth for the *string* written to
 * `audit_logs.event`. `SECURITY.md` §9.1 is authoritative for these names;
 * the constants here exist so code and documentation cannot drift apart
 * again (a rename is now a one-line change that fails tests if the doc and
 * the code disagree).
 *
 * Only events enumerated in SECURITY.md §9.1 are listed. Events that are
 * documented elsewhere (e.g. `notification.channel_disabled` in
 * NOTIFICATIONS.md §9, `auth.admin_provisioned` in DECISIONS.md) are emitted
 * with their literal string at the call site, exactly as documented.
 */
final class AuditEvent
{
    // --- Authentication (SECURITY.md §9.1) ---
    public const AUTH_LOGIN_SUCCESS = 'auth.login.success';

    public const AUTH_LOGIN_FAILURE = 'auth.login.failure';

    public const AUTH_LOGIN_THROTTLED = 'auth.login.throttled';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_PASSWORD_RESET_REQUESTED = 'auth.password.reset.requested';

    public const AUTH_PASSWORD_RESET_COMPLETED = 'auth.password.reset.completed';

    // --- Incidents (SECURITY.md §9.1) ---
    public const INCIDENT_CREATED = 'incident.created';

    public const INCIDENT_ESCALATED = 'incident.escalated';

    public const INCIDENT_ACKNOWLEDGED = 'incident.acknowledged';

    public const INCIDENT_RESOLVED = 'incident.resolved';

    // --- Configuration (SECURITY.md §9.1) ---
    public const RULE_CHANGED = 'rule.changed';

    public const SETTINGS_CHANGED = 'settings.changed';

    public const SETTINGS_PULLED = 'settings.pulled';

    public const SETTINGS_ROLLED_BACK = 'settings.rolled_back';

    // --- Notification channels (SECURITY.md §9.1) ---
    public const CHANNEL_SECRET_UPDATED = 'channel.secret.updated';

    public const CHANNEL_TESTED = 'channel.tested';

    // --- Status page (SECURITY.md §9.1) ---
    public const STATUS_PAGE_VISIBILITY_CHANGED = 'status_page.visibility.changed';

    public const STATUS_PAGE_UNLOCK_FAILED = 'status_page.unlock.failed';

    // --- Retention / SSRF (SECURITY.md §9.1) ---
    public const RETENTION_PRUNED = 'retention.pruned';

    public const SSRF_BLOCKED = 'ssrf.blocked';

    /**
     * The full canonical event-name set from SECURITY.md §9.1.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AUTH_LOGIN_SUCCESS,
            self::AUTH_LOGIN_FAILURE,
            self::AUTH_LOGIN_THROTTLED,
            self::AUTH_LOGOUT,
            self::AUTH_PASSWORD_RESET_REQUESTED,
            self::AUTH_PASSWORD_RESET_COMPLETED,
            self::INCIDENT_CREATED,
            self::INCIDENT_ESCALATED,
            self::INCIDENT_ACKNOWLEDGED,
            self::INCIDENT_RESOLVED,
            self::RULE_CHANGED,
            self::SETTINGS_CHANGED,
            self::SETTINGS_PULLED,
            self::SETTINGS_ROLLED_BACK,
            self::CHANNEL_SECRET_UPDATED,
            self::CHANNEL_TESTED,
            self::STATUS_PAGE_VISIBILITY_CHANGED,
            self::STATUS_PAGE_UNLOCK_FAILED,
            self::RETENTION_PRUNED,
            self::SSRF_BLOCKED,
        ];
    }
}
