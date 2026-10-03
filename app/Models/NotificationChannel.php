<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Configured delivery channel (DATABASE.md §3.13).
 *
 * @property int $id
 * @property string $type 'email'|'telegram'
 * @property string $name
 * @property bool $enabled
 * @property array<string, mixed>|null $config
 * @property string|null $secret_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class NotificationChannel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'notification_channels';

    protected $fillable = [
        'type',
        'name',
        'enabled',
        'config',
        'secret_ref',
    ];

    /**
     * Never serialize the decrypted secret to arrays/JSON (SECURITY.md §4.2
     * rule 4: the current value is never sent to the browser). Direct
     * attribute access (`$channel->secret_ref`) is unaffected.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'secret_ref' => 'encrypted',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'channel_id');
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'website_notification_channel', 'channel_id', 'website_id')
            ->withPivot('created_at');
    }
}
