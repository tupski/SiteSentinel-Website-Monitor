<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Independently-configured public status page (DATABASE.md §3.22, ADR-031).
 *
 * A website points at a page via `websites.status_page_id`; a website with a
 * NULL `status_page_id` falls back to the page with `is_default = 1`.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 * @property string $visibility_mode
 * @property string|null $password_hash
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StatusPage extends Model
{
    use HasFactory;

    public const MODE_PRIVATE = 'Private';

    public const MODE_PUBLIC = 'Public';

    public const MODE_PASSWORD_PROTECTED = 'Password Protected';

    public const DEFAULT_SLUG = 'status';

    protected $table = 'status_pages';

    protected $fillable = [
        'name',
        'slug',
        'is_default',
        'visibility_mode',
        'password_hash',
        'created_by',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * The default page (target of the legacy `/status` redirect and the
     * fallback for websites without an explicit page). Creates a Private
     * default page on first access when none exists.
     */
    public static function resolveDefault(): self
    {
        /** @var self $page */
        $page = self::query()->where('is_default', true)->orderBy('id')->first()
            ?? self::query()->firstOrCreate(
                ['slug' => self::DEFAULT_SLUG],
                [
                    'name' => 'Status page',
                    'is_default' => true,
                    'visibility_mode' => self::MODE_PRIVATE,
                    'password_hash' => null,
                ]
            );

        return $page;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Websites explicitly assigned to this page. A NULL assignment is not
     * included — those belong to the default page only.
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class, 'status_page_id');
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

    public function hasUsablePassword(): bool
    {
        return (string) ($this->password_hash ?? '') !== '';
    }
}
