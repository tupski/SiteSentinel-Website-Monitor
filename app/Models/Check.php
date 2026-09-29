<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Check extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'check_key',
        'started_at',
        'finished_at',
        'duration_ms',
        'http_status',
        'final_url',
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
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'redirect_chain' => 'array',
            'availability_state' => 'string',
            'security_state' => 'string',
            'score' => 'integer',
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
}
