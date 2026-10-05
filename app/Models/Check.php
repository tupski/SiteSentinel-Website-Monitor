<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Check extends Model
{
    use HasFactory, MassPrunable;

    protected $fillable = [
        'website_id',
        'check_key',
        'started_at',
        'finished_at',
        'duration_ms',
        'http_status',
        'final_url',
        'resolved_ip',
        'redirect_chain',
        'response_size_bytes',
        'ssl_valid',
        'ssl_issuer',
        'ssl_expires_at',
        'title',
        'content_hash',
        'error_type',
        'error_message',
        'availability_state',
        'security_state',
        'score',
        'triggered_rules',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'redirect_chain' => 'array',
            'resolved_ip' => 'string',
            'availability_state' => 'string',
            'security_state' => 'string',
            'score' => 'integer',
            'triggered_rules' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(CheckExtraction::class);
    }

    /**
     * Retention: prune check telemetry older than `retention.checks_days`
     * (PRD FR-91, AC-19, DATABASE.md §4). `check_extractions` cascade on
     * delete, so no orphan rows remain.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // Read through the settings accessor (ADR-035, ADR-043) so an admin
        // edit on the Settings page is actually applied; falls back to the
        // config default when no row is stored.
        $days = max(1, (int) settings(SettingsRepository::RETENTION_CHECKS_DAYS));

        return self::query()->where('created_at', '<=', now()->subDays($days));
    }
}
