<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply stored system settings to the running request (ADR-035, ADR-043).
 *
 * This is the runtime "consumer" that fixes the settings-saved-but-not-applied
 * bug: before this existed the admin could change site identity, branding and
 * timezone and nothing read the stored rows.
 *
 *  - The configured timezone is applied to PHP + Carbon for the request so
 *    every rendered timestamp honours the admin's choice.
 *  - Site identity and branding asset URLs are shared with all views so the
 *    header, titles, login page and favicon reflect the stored values.
 *
 * Reads go through {@see SettingsRepository} (a per-request memoized singleton),
 * so this costs at most one cached map read and degrades to config defaults when
 * the table/row is absent. Never throws: a settings failure must not 500 a page.
 */
final class ApplySystemSettings
{
    /**
     * The PHP default timezone before this request applied the setting, so it
     * can be restored on terminate (long-lived workers/tests must not leak the
     * per-request timezone into the next request).
     */
    private ?string $previousTimezone = null;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->applyTimezone();
            $this->shareBranding();
        } catch (\Throwable $e) {
            // A settings read must never break a request.
            report($e);
        }

        return $next($request);
    }

    /**
     * Restore the pre-request timezone once the response is sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->previousTimezone !== null) {
            date_default_timezone_set($this->previousTimezone);
        }
    }

    private function applyTimezone(): void
    {
        $timezone = $this->settings->string(SettingsRepository::TIMEZONE, (string) config('app.timezone', 'UTC'));

        if ($timezone === '' || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $this->previousTimezone = date_default_timezone_get();

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    private function shareBranding(): void
    {
        $logoPath = $this->settings->get(SettingsRepository::SITE_LOGO);
        $faviconPath = $this->settings->get(SettingsRepository::FAVICON);

        View::share([
            'siteName' => $this->settings->string(SettingsRepository::SITE_NAME, (string) config('app.name', 'SiteSentinel')),
            'siteDescription' => $this->settings->string(SettingsRepository::SITE_DESCRIPTION),
            'siteLogoUrl' => $this->assetUrl($logoPath),
            'faviconUrl' => $this->assetUrl($faviconPath),
        ]);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return Storage::disk('public')->url($path);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
