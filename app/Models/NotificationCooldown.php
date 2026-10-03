<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Suppression window state (DATABASE.md §3.16).
 *
 * @property int $id
 * @property int|null $incident_id
 * @property int|null $channel_id
 * @property string $event_kind
 * @property string $cooldown_key
 * @property Carbon $window_started_at
 * @property Carbon $expires_at
 * @property int $suppressed_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationCooldown extends Model
{
    use HasFactory, MassPrunable;

    protected $table = 'notification_cooldowns';

    protected $fillable = [
        'incident_id',
        'channel_id',
        'event_kind',
        'cooldown_key',
        'window_started_at',
        'expires_at',
        'suppressed_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'expires_at' => 'datetime',
            'suppressed_count' => 'integer',
        ];
    }

    /**
     * Transient state: prune cooldown windows whose expiry has passed
     * (DATABASE.md §4 "notification_cooldowns — transient — delete by
     * expires_at").
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }
}
