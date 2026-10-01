<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Incident event immutable timeline record (DATABASE.md §3.11).
 *
 * @property int $id
 * @property int $incident_id
 * @property string $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_user_id
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
final class IncidentEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'incident_events';

    protected $fillable = [
        'incident_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
