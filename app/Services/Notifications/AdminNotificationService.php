<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AdminNotification;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use Throwable;

/**
 * In-app admin notification centre generation (Phase G, ADR-038,
 * NOTIFICATIONS.md §15).
 *
 * Additive to — and completely independent of — outbound delivery. This service
 * never touches {@see NotificationDispatcher}, `notification_logs`, cooldown,
 * suppression, or the circuit breaker: it only writes per-admin rows to
 * `admin_notifications` from the same incident/security/config events that are
 * already recorded to `audit_logs` (NOTIFICATIONS.md §15.2).
 *
 * Design points:
 *  - **Recipient rule (documented):** notifications are fanned out to **all
 *    active admins** (`role = admin`, `is_active = true`) with a single query.
 *    Incident/security events are system-generated (the queue worker, not a
 *    human actor), so there is no "acting admin" to target; the in-app centre is
 *    an operational surface every admin should see. See the report/CHANGELOG.
 *  - **Idempotency:** every real event carries a stable dedupe key; a retried or
 *    repeated generation path does not create a second row. The frozen schema
 *    has a **global** UNIQUE index on `dedupe_key`, so the key is additionally
 *    scoped per recipient (`{base}:u{userId}`) — otherwise a multi-admin fan-out
 *    would collide on the second admin. The canonical base key stays exactly as
 *    documented (e.g. `incident.down:{incidentId}`).
 *  - **Best-effort:** every write is wrapped in try/catch + report(); a
 *    notification failure must never break a monitoring job or an admin request
 *    (AGENTS.md §12).
 *  - **No sensitive infrastructure detail:** titles/bodies are built from the
 *    admin-facing website/channel **name** only and pass through
 *    {@see MessageRedactor}. No resolved IP, host, rule id, snapshot path,
 *    redirect target, or secret is ever included.
 *  - **Link safety:** `link_url` is an internal relative path only; anything
 *    absolute/external is dropped (never an open-redirect surface).
 */
final class AdminNotificationService
{
    public const LINK_INCIDENTS = '/admin/incidents';

    public const LINK_WEBSITES = '/admin/websites';

    public const LINK_NOTIFICATION = '/admin/notifications';

    public const LINK_SETTINGS = '/admin/settings';

    /**
     * Create one notification for one admin.
     *
     * `$dedupeKey` is the canonical per-event key (e.g. `incident.down:12`); it
     * is scoped per recipient internally so the global UNIQUE index holds for a
     * multi-admin fan-out. Pass null for a deliberately non-deduplicated row.
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?string $severity,
        ?string $linkUrl,
        ?string $dedupeKey,
    ): ?AdminNotification {
        if ($userId <= 0 || ! in_array($type, AdminNotification::types(), true)) {
            return null;
        }

        try {
            $attributes = [
                'type' => $type,
                'title' => $this->clean($title, 255),
                'body' => $body !== null ? $this->clean($body, 500) : null,
                'severity' => $this->severity($severity),
                'link_url' => $this->sanitizeLink($linkUrl),
            ];

            $key = ($dedupeKey !== null && $dedupeKey !== '')
                ? $this->scopedKey($dedupeKey, $userId)
                : null;

            if ($key === null) {
                return AdminNotification::query()->create(['user_id' => $userId] + $attributes);
            }

            return AdminNotification::createDeduped($userId, $key, $attributes);
        } catch (Throwable $e) {
            // Best-effort: a failed in-app write must never break the caller.
            report($e);

            return null;
        }
    }

    /**
     * Create the same notification for a list of recipients.
     *
     * @param  iterable<int|string>  $userIds
     * @return int number of newly created rows (dedupe hits are not counted)
     */
    public function notifyRecipients(
        iterable $userIds,
        string $type,
        string $title,
        ?string $body,
        ?string $severity,
        ?string $linkUrl,
        ?string $dedupeKey,
    ): int {
        $created = 0;

        foreach ($userIds as $userId) {
            $notification = $this->notify((int) $userId, $type, $title, $body, $severity, $linkUrl, $dedupeKey);

            if ($notification !== null && $notification->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Fan a notification out to every active admin (single query).
     *
     * @param  list<int>|null  $recipientIds  explicit recipients, else all active admins
     */
    public function notifyActiveAdmins(
        string $type,
        string $title,
        ?string $body,
        ?string $severity,
        ?string $linkUrl,
        ?string $dedupeKey,
        ?array $recipientIds = null,
    ): int {
        return $this->notifyRecipients(
            $recipientIds ?? $this->activeAdminIds(),
            $type,
            $title,
            $body,
            $severity,
            $linkUrl,
            $dedupeKey,
        );
    }

    /**
     * Every active admin id, in one query. MVP is admin-only; viewers never
     * receive in-app notifications (the centre is behind the `admin` gate).
     *
     * @return list<int>
     */
    public function activeAdminIds(): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Unread count for one admin (`read_at IS NULL`), served by
     * `idx_admin_notifications_user_read_created`.
     */
    public function unreadCount(int $userId): int
    {
        return AdminNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /*
    |----------------------------------------------------------------------
    | Event helpers — build safe, human-readable content per event type
    |----------------------------------------------------------------------
    */

    /**
     * Emit the in-app notification for an incident opening or resolving.
     *
     * @param  string  $kind  'opened'|'resolved'
     */
    public function recordIncidentTransition(Incident $incident, string $kind): void
    {
        try {
            /** @var Website|null $website */
            $website = $incident->website ?? Website::query()->find($incident->website_id);
            $name = $this->websiteLabel($website);
            $link = self::LINK_INCIDENTS.'/'.$incident->id;
            $isSecurity = $incident->type === 'security';

            if ($kind === 'opened') {
                if ($isSecurity) {
                    $this->notifyActiveAdmins(
                        AdminNotification::TYPE_SECURITY_INCIDENT_DETECTED,
                        'Security incident detected on '.$name,
                        'A security incident was detected. Open the incident to review the evidence.',
                        AdminNotification::SEVERITY_DANGER,
                        $link,
                        'security.incident.detected:'.$incident->id,
                    );
                } else {
                    $this->notifyActiveAdmins(
                        AdminNotification::TYPE_INCIDENT_DOWN,
                        'Website '.$name.' is DOWN',
                        'Repeated availability failures opened an incident.',
                        AdminNotification::SEVERITY_DANGER,
                        $link,
                        'incident.down:'.$incident->id,
                    );
                }

                return;
            }

            if ($isSecurity) {
                $this->notifyActiveAdmins(
                    AdminNotification::TYPE_SECURITY_INCIDENT_RESOLVED,
                    'Security incident resolved on '.$name,
                    'The security incident was resolved.',
                    AdminNotification::SEVERITY_SUCCESS,
                    $link,
                    'security.incident.resolved:'.$incident->id,
                );
            } else {
                $this->notifyActiveAdmins(
                    AdminNotification::TYPE_INCIDENT_RECOVERED,
                    'Website '.$name.' recovered',
                    'The availability incident was resolved after sustained healthy checks.',
                    AdminNotification::SEVERITY_SUCCESS,
                    $link,
                    'incident.recovered:'.$incident->id,
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Derive opened/resolved in-app notifications from the same before/after
     * open-incident diff the outbound intent helper uses (NOTIFICATIONS.md §15.2).
     * Routine checks with no state change produce nothing.
     *
     * @param  array<string, array{id: int, severity: string, status: string}>  $before
     */
    public function recordIncidentTransitionsFromDiff(int $websiteId, array $before): void
    {
        try {
            $after = NotificationIntents::snapshotOpen($websiteId);

            $beforeById = [];
            foreach ($before as $snapshot) {
                $beforeById[(int) $snapshot['id']] = $snapshot;
            }

            // Resolved: an incident that was open is no longer open.
            foreach ($before as $type => $snapshot) {
                if (! isset($after[$type]) || $after[$type]['id'] !== $snapshot['id']) {
                    /** @var Incident|null $resolved */
                    $resolved = Incident::query()->find($snapshot['id']);
                    if ($resolved !== null && $resolved->status === 'RESOLVED') {
                        $this->recordIncidentTransition($resolved, 'resolved');
                    }
                }
            }

            // Opened: a type is newly open with an incident id not previously open.
            foreach ($after as $type => $current) {
                $isNewForType = ! isset($before[$type]) || $before[$type]['id'] !== $current['id'];

                if ($isNewForType && ! isset($beforeById[(int) $current['id']])) {
                    /** @var Incident|null $incident */
                    $incident = Incident::query()->find($current['id']);
                    if ($incident !== null) {
                        $this->recordIncidentTransition($incident, 'opened');
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Emit `monitoring.config.changed` for a meaningful monitoring/admin action
     * (website create/update/enable/disable/delete/bulk). One row per action.
     */
    public function recordMonitoringConfigChanged(
        string $action,
        string $summary,
        ?string $linkUrl = null,
        ?int $subjectId = null,
        ?int $actorId = null,
    ): void {
        $this->recordConfigChanged(
            AdminNotification::TYPE_MONITORING_CONFIG_CHANGED,
            'Monitoring configuration changed',
            $action,
            $summary,
            $linkUrl ?? self::LINK_WEBSITES,
            $subjectId,
            $actorId,
        );
    }

    /**
     * Emit `notification.config.changed` for a notification channel or system
     * settings change.
     */
    public function recordNotificationConfigChanged(
        string $action,
        string $summary,
        ?string $linkUrl = null,
        ?int $subjectId = null,
        ?int $actorId = null,
    ): void {
        $this->recordConfigChanged(
            AdminNotification::TYPE_NOTIFICATION_CONFIG_CHANGED,
            'Notification configuration changed',
            $action,
            $summary,
            $linkUrl ?? self::LINK_NOTIFICATION,
            $subjectId,
            $actorId,
        );
    }

    /*
    |----------------------------------------------------------------------
    | Link + content safety
    |----------------------------------------------------------------------
    */

    /**
     * `link_url` is an internal relative path only: must start with a single
     * `/`, carry no scheme/host, no protocol-relative `//`, no backslashes, and
     * no control characters. Anything else is dropped (null), so the centre can
     * never become an open-redirect surface (ADR-038, NOTIFICATIONS.md §15.4).
     */
    public static function isValidInternalPath(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048) {
            return false;
        }

        // Control characters (including CR/LF) are never valid in a path.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if ($url[0] !== '/') {
            return false;
        }

        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return false;
        }

        if (str_contains($url, '\\') || str_contains($url, '://')) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return false;
        }

        return true;
    }

    /**
     * Return the link only when it is a valid internal relative path, else null.
     */
    public function sanitizeLink(?string $linkUrl): ?string
    {
        if ($linkUrl === null) {
            return null;
        }

        $linkUrl = trim($linkUrl);

        return self::isValidInternalPath($linkUrl) ? $linkUrl : null;
    }

    /*
    |----------------------------------------------------------------------
    | Internals
    |----------------------------------------------------------------------
    */

    private function recordConfigChanged(
        string $type,
        string $title,
        string $action,
        string $summary,
        string $linkUrl,
        ?int $subjectId,
        ?int $actorId,
    ): void {
        try {
            $dedupeKey = sprintf(
                '%s:%s:%s:%d:a%d',
                $type,
                $action,
                $subjectId ?? 'x',
                now()->getTimestamp(),
                $actorId ?? 0,
            );

            $this->notifyActiveAdmins($type, $title, $summary, AdminNotification::SEVERITY_INFO, $linkUrl, $dedupeKey);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function scopedKey(string $base, int $userId): string
    {
        return mb_substr($base.':u'.$userId, 0, 191);
    }

    private function clean(string $value, int $max): string
    {
        $redacted = trim((string) MessageRedactor::redact($value));

        if ($redacted === '') {
            $redacted = 'Notification';
        }

        return mb_substr($redacted, 0, $max);
    }

    private function severity(?string $severity): ?string
    {
        return in_array($severity, [
            AdminNotification::SEVERITY_INFO,
            AdminNotification::SEVERITY_SUCCESS,
            AdminNotification::SEVERITY_WARNING,
            AdminNotification::SEVERITY_DANGER,
        ], true) ? $severity : null;
    }

    private function websiteLabel(?Website $website): string
    {
        $name = trim((string) ($website?->name ?? ''));

        return $name !== '' ? $name : 'a monitored website';
    }
}
