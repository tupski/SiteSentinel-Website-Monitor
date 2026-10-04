<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Key/value application setting (DATABASE.md §3.17, ADR-035).
 *
 * Deliberately thin: the typed getters, key registry, defaults and caching all
 * live in {@see SettingsRepository}. This model only
 * maps the frozen `settings` columns.
 *
 * `is_encrypted` is part of the frozen schema but is NOT used at MVP — no
 * secret is stored in this table (ADR-035 secret boundary).
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property bool $is_encrypted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * Cast the stored string to the given scalar/json type.
     */
    public function castValue(string $type): mixed
    {
        $raw = $this->value;

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            'float' => (float) $raw,
            'json' => is_string($raw) && $raw !== '' ? json_decode($raw, true) : null,
            default => $raw,
        };
    }
}
