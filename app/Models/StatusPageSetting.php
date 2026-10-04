<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Status page configuration singleton (DATABASE.md §3.18).
 *
 * @deprecated Phase 11 (ADR-031) — superseded by {@see StatusPage}. The table
 * is retained for one release for rollback safety; do NOT use this model for
 * new code. Use `StatusPage` (per page) instead.
 *
 * @property int $id
 * @property string $visibility_mode
 * @property string|null $password_hash
 * @property string|null $slug
 * @property array<string, mixed>|null $branding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StatusPageSetting extends Model
{
    use HasFactory;

    public const MODE_PRIVATE = 'Private';

    public const MODE_PUBLIC = 'Public';

    public const MODE_PASSWORD_PROTECTED = 'Password Protected';

    protected $table = 'status_page_settings';

    protected $fillable = [
        'visibility_mode',
        'password_hash',
        'slug',
        'branding',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
        ];
    }

    public static function singleton(): self
    {
        /** @var self $setting */
        $setting = self::query()->firstOrCreate(
            ['id' => 1],
            [
                'visibility_mode' => self::MODE_PRIVATE,
                'password_hash' => null,
                'slug' => null,
                'branding' => null,
            ]
        );

        return $setting;
    }

    public function isPrivate(): bool
    {
        return $this->visibility_mode === self::MODE_PRIVATE;
    }

    public function isPublic(): bool
    {
        return $this->visibility_mode === self::MODE_PUBLIC;
    }

    public function isPasswordProtected(): bool
    {
        return $this->visibility_mode === self::MODE_PASSWORD_PROTECTED;
    }
}
