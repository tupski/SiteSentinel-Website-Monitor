<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Detection rule registry (DATABASE.md §3.8).
 *
 * @property int $id
 * @property string $rule_id
 * @property string $name
 * @property string $category
 * @property string $severity
 * @property int $default_weight
 * @property bool $enabled
 * @property array|null $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DetectionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'name',
        'category',
        'severity',
        'default_weight',
        'enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'default_weight' => 'integer',
            'config' => 'array',
        ];
    }

    public function websiteSettings(): HasMany
    {
        return $this->hasMany(WebsiteRuleSetting::class);
    }
}
