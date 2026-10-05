<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Incident lifecycle record (DATABASE.md §3.10, PRD.md §12).
 *
 * @property int $id
 * @property int $website_id
 * @property string $type 'availability'|'security'
 * @property string|null $category
 * @property string $severity 'INFO'|'WARNING'|'CRITICAL'
 * @property string $status 'DETECTED'|'ACKNOWLEDGED'|'RESOLVED'
 * @property int $score
 * @property array<string, mixed>|null $triggered_rules
 * @property string|null $message
 * @property array<string, mixed>|null $technical_metadata
 * @property string $dedupe_key
 * @property Carbon $detected_at
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_mode 'manual'|'auto'
 * @property string|null $resolution_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Incident extends Model
{
    use HasFactory, MassPrunable;

    protected $table = 'incidents';

    protected $fillable = [
        'website_id',
        'type',
        'category',
        'severity',
        'status',
        'score',
        'triggered_rules',
        'message',
        'technical_metadata',
        'dedupe_key',
        'detected_at',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'resolution_mode',
        'resolution_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'triggered_rules' => 'array',
            'technical_metadata' => 'array',
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('created_at', 'asc')->orderBy('id', 'asc');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['DETECTED', 'ACKNOWLEDGED'], true);
    }

    public function isResolved(): bool
    {
        return $this->status === 'RESOLVED';
    }

    /**
     * Retention: prune incident history older than `retention.incidents_days`
     * (default 365d; PRD FR-92, AC-19, DATABASE.md §4). `incident_events`
     * cascade; snapshot/notification-log FKs are nullOnDelete, so no reference
     * is corrupted (FR-95).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(
            max(1, (int) settings(SettingsRepository::RETENTION_INCIDENTS_DAYS))
        ));
    }
}
