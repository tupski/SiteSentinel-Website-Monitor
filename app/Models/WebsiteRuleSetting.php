<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-website rule overrides (DATABASE.md §3.9).
 *
 * @property int $id
 * @property int $website_id
 * @property int $detection_rule_id
 * @property bool|null $enabled
 * @property int|null $weight_override
 * @property int|null $threshold_override
 * @property array|null $ignored_keywords
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WebsiteRuleSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'detection_rule_id',
        'enabled',
        'weight_override',
        'threshold_override',
        'ignored_keywords',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'weight_override' => 'integer',
            'threshold_override' => 'integer',
            'ignored_keywords' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
