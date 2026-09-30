<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebsiteBaseline extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'version',
        'is_active',
        'http_status',
        'final_url',
        'title',
        'content_hash',
        'hash_algorithm',
        'response_size_bytes',
        'keyword_counts',
        'external_link_count',
        'external_domains',
        'ssl_valid',
        'ssl_issuer',
        'ssl_expires_at',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ssl_valid' => 'boolean',
            'ssl_expires_at' => 'datetime',
            'captured_at' => 'datetime',
            'keyword_counts' => 'array',
            'external_domains' => 'array',
            'response_size_bytes' => 'integer',
            'external_link_count' => 'integer',
            'version' => 'integer',
            'http_status' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
