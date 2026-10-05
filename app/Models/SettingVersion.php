<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable settings snapshot (ADR-043).
 *
 * One row per settings save / pull / rollback. `snapshot` is the full typed
 * key => value map at that point; `checksum` is the sha256 of its canonical
 * form so identical states dedupe and drift is detectable. Rows are never
 * updated.
 *
 * @property int $id
 * @property int $version
 * @property string|null $label
 * @property array<string, mixed> $snapshot
 * @property string $checksum
 * @property string $source
 * @property int|null $author_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
final class SettingVersion extends Model
{
    public const SOURCE_SAVE = 'save';

    public const SOURCE_PULL = 'pull';

    public const SOURCE_ROLLBACK = 'rollback';

    protected $table = 'setting_versions';

    protected $fillable = [
        'version',
        'label',
        'snapshot',
        'checksum',
        'source',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Deterministic checksum over a settings map (order-independent).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function checksumFor(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', (string) json_encode($snapshot));
    }

    /**
     * Human label for the version's origin (used in the history table).
     */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_PULL => 'Pull update',
            self::SOURCE_ROLLBACK => 'Rollback',
            default => 'Save',
        };
    }
}
