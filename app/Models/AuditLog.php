<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Audit trail entry (DATABASE.md §3.19).
 *
 * Rows are immutable: they are inserted once and never updated. The model
 * therefore has no updated_at column and disables mass-assignment guards
 * only for the AuditLogger service, which is the single write path.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $metadata
 * @property Carbon|null $created_at
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
