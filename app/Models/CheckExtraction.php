<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CheckExtraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'check_id',
        'website_id',
        'keywords',
        'external_domains',
        'suspicious_patterns',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'external_domains' => 'array',
            'suspicious_patterns' => 'array',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
