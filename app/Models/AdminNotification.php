<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-admin in-app notification centre row (DATABASE.md §3.24, ADR-038).
 *
 * Decoupled from outbound channel delivery: this is not a provider, does not
 * run on the `notifications` queue, and does not participate in
 * `notification_logs` suppression/cooldown. Rows are generated from the
 * existing incident/security/config events (the same events written to
 * `audit_logs`).
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property string|null $severity
 * @property string|null $link_url
 * @property Carbon|null $read_at
 * @property string|null $dedupe_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AdminNotification extends Model
{
    use HasFactory;

    protected $table = 'admin_notifications';

    // --- Event types (ADR-038; NOTIFICATIONS.md §15.2) ---
    public const TYPE_INCIDENT_DOWN = 'incident.down';

    public const TYPE_INCIDENT_RECOVERED = 'incident.recovered';

    public const TYPE_SECURITY_INCIDENT_DETECTED = 'security.incident.detected';

    public const TYPE_SECURITY_INCIDENT_RESOLVED = 'security.incident.resolved';

    public const TYPE_MONITORING_CONFIG_CHANGED = 'monitoring.config.changed';

    public const TYPE_NOTIFICATION_CONFIG_CHANGED = 'notification.config.changed';

    // --- Severity / icon hints (ADR-038) ---
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_SUCCESS = 'success';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_DANGER = 'danger';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'severity',
        'link_url',
        'read_at',
        'dedupe_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The full allowed event `type` set (ADR-038). Routine checks are
     * deliberately absent — they never notify.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_INCIDENT_DOWN,
            self::TYPE_INCIDENT_RECOVERED,
            self::TYPE_SECURITY_INCIDENT_DETECTED,
            self::TYPE_SECURITY_INCIDENT_RESOLVED,
            self::TYPE_MONITORING_CONFIG_CHANGED,
            self::TYPE_NOTIFICATION_CONFIG_CHANGED,
        ];
    }

    /**
     * The owning admin. Rows are per-admin and cascade with the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Unread rows only (`read_at IS NULL`). Hot query served by
     * `idx_admin_notifications_user_read_created`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Stamp the row as read. Idempotent — an already-read row is unchanged.
     */
    public function markAsRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        return $this->forceFill(['read_at' => now()])->save();
    }

    /**
     * Idempotent creation path used by callers (Phase G): a repeated generation
     * for the same admin + dedupe key returns the existing row rather than
     * inserting a duplicate (ADR-038).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createDeduped(int $userId, string $dedupeKey, array $attributes = []): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $userId, 'dedupe_key' => $dedupeKey],
            $attributes,
        );
    }
}
