<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Web Push subscription material (DATABASE.md §3.23, ADR-032).
 *
 * `endpoint`, `p256dh`, and `auth` are subscription secrets: encrypted at
 * rest (SECURITY.md §4) and never serialized to arrays/JSON. The deterministic
 * `endpoint_hash` (SHA-256 of the endpoint) backs the unique constraint and is
 * safe to query — the raw endpoint is never used as a lookup key.
 */
final class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'website_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'user_agent',
        'enabled',
    ];

    /**
     * Never serialize subscription secrets (SECURITY.md §4.2 rule 4).
     *
     * @var list<string>
     */
    protected $hidden = [
        'endpoint',
        'p256dh',
        'auth',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'endpoint' => 'encrypted',
            'p256dh' => 'encrypted',
            'auth' => 'encrypted',
        ];
    }

    /**
     * Deterministic hash used for the unique index and upsert lookup, so the
     * raw (encrypted, non-deterministic) endpoint ciphertext is never queried.
     */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    public function toSubscriptionArray(): array
    {
        return [
            'endpoint' => (string) $this->endpoint,
            'keys' => [
                'p256dh' => (string) $this->p256dh,
                'auth' => (string) $this->auth,
            ],
        ];
    }
}
