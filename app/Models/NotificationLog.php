<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Delivery attempt record (DATABASE.md §3.15).
 *
 * @property int $id
 * @property int|null $incident_id
 * @property int|null $channel_id
 * @property string $status
 * @property int $attempt
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property string|null $dedupe_key
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 */
final class NotificationLog extends Model
{
    use HasFactory, MassPrunable;

    protected $table = 'notification_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'incident_id',
        'channel_id',
        'status',
        'attempt',
        'provider_message_id',
        'error',
        'dedupe_key',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    /**
     * Retention: prune delivery logs older than `retention.notification_logs_days`
     * (PRD FR-93, AC-19, DATABASE.md §4).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(
            max(1, (int) settings(SettingsRepository::RETENTION_NOTIFICATION_LOGS_DAYS))
        ));
    }
}
