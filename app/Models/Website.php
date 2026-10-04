<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Security\SsrfUrlValidator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Monitored website (DATABASE.md §3.4).
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $scheme
 * @property string $host
 * @property bool $is_active
 * @property bool $is_visible_on_status
 * @property string|null $status_alias
 * @property int $check_interval_seconds
 * @property int $timeout_seconds
 * @property int $expected_status
 * @property string|null $expected_title
 * @property string|null $expected_final_domain
 * @property bool $follow_redirects
 * @property string|null $note
 * @property bool $monitor_ssl
 * @property bool $monitor_redirects
 * @property bool $monitor_content
 * @property bool $monitor_security
 * @property int|null $current_baseline_id
 * @property string|null $status_availability
 * @property string|null $status_security
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property string|null $last_lock_token
 * @property Carbon|null $locked_at
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Website extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'url',
        'scheme',
        'host',
        'is_active',
        'is_visible_on_status',
        'status_page_id',
        'status_alias',
        'check_interval_seconds',
        'timeout_seconds',
        'expected_status',
        'expected_title',
        'expected_final_domain',
        'follow_redirects',
        'note',
        'monitor_ssl',
        'monitor_redirects',
        'monitor_content',
        'monitor_security',
        'current_baseline_id',
        'status_availability',
        'status_security',
        'last_checked_at',
        'next_check_at',
        'last_lock_token',
        'locked_at',
        'consecutive_failures',
        'consecutive_successes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible_on_status' => 'boolean',
            'follow_redirects' => 'boolean',
            'monitor_ssl' => 'boolean',
            'monitor_redirects' => 'boolean',
            'monitor_content' => 'boolean',
            'monitor_security' => 'boolean',
            'check_interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'expected_status' => 'integer',
            'current_baseline_id' => 'integer',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * Active baseline for this website.
     */
    public function currentBaseline(): BelongsTo
    {
        return $this->belongsTo(WebsiteBaseline::class, 'current_baseline_id');
    }

    /**
     * Status page this website is explicitly assigned to (nullable). A NULL
     * assignment resolves to the default status page (ADR-031).
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class, 'status_page_id');
    }

    /**
     * Effective page for this website: its assigned page, else the default.
     */
    public function effectiveStatusPage(): StatusPage
    {
        return $this->statusPage ?? StatusPage::resolveDefault();
    }

    /**
     * The stored URL, but only when its scheme is http/https.
     *
     * `url` is normalised at write time by {@see SsrfUrlValidator}
     * (scheme allowlist, credentials rejected), so this is a defence-in-depth
     * guard for the admin table's outbound anchor — never blindly trust a stored
     * value in `href`. Returns null when the scheme is not a safe web scheme, so
     * the view can fall back to plain text.
     */
    public function safeUrl(): ?string
    {
        $url = (string) $this->url;
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Per-website rule setting overrides.
     */
    public function ruleSettings(): HasMany
    {
        return $this->hasMany(WebsiteRuleSetting::class);
    }

    /**
     * Scoped notification channels. Absence rule: no rows = all enabled.
     */
    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'website_notification_channel', 'website_id', 'channel_id')
            ->withPivot('created_at');
    }
}
