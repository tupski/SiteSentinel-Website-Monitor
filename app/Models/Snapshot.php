<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence snapshot (DATABASE.md §3.12).
 *
 * @property int $id
 * @property int $website_id
 * @property int|null $check_id
 * @property int|null $incident_id
 * @property string|null $html_path
 * @property array|null $headers
 * @property string|null $final_url
 * @property string|null $title
 * @property array|null $keywords
 * @property array|null $external_links
 * @property array|null $redirect_chain
 * @property int|null $size_bytes
 * @property Carbon $captured_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Snapshot extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [
        'website_id',
        'check_id',
        'incident_id',
        'html_path',
        'headers',
        'final_url',
        'title',
        'keywords',
        'external_links',
        'redirect_chain',
        'size_bytes',
        'captured_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'keywords' => 'array',
            'external_links' => 'array',
            'redirect_chain' => 'array',
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Retention: prune evidence snapshots older than `retention.snapshots_days`
     * (PRD §16, AC-19). Open-incident evidence is preserved until its incident
     * is resolved; the model's own `expires_at` is the canonical window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(
            max(1, (int) config('sentinel.retention.snapshots_days', 14))
        ));
    }

    /**
     * Remove the on-disk HTML artefact alongside the row so pruning does not
     * leak orphaned files (SECURITY.md §6).
     */
    protected function pruning(): void
    {
        $path = (string) ($this->html_path ?? '');
        if ($path !== '') {
            try {
                Storage::disk((string) config('sentinel.snapshots_disk', 'local'))->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
