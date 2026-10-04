<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Settings\SettingsRepository;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin system settings (ADR-035).
 *
 * Exposes only presentational/identity settings — site name, description,
 * logo, favicon and timezone. Infrastructure secrets (APP_KEY, DB, SMTP, API,
 * queue credentials) are NOT part of the registry and can never be written
 * through this endpoint: the request whitelists the keys and the repository
 * rejects anything not registered.
 */
final class SystemSettingController extends Controller
{
    /** Public-disk directory holding uploaded branding assets. */
    private const BRANDING_DIR = 'branding';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'values' => $this->settings->all(),
            'timezones' => $this->groupedTimezones(),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->settings->set(SettingsRepository::SITE_NAME, (string) $data['site_name']);
        $this->settings->set(SettingsRepository::TIMEZONE, (string) $data['timezone']);

        // An empty description is stored as absence so its default applies.
        $description = trim((string) ($data['site_description'] ?? ''));
        if ($description === '') {
            $this->settings->forget(SettingsRepository::SITE_DESCRIPTION);
        } else {
            $this->settings->set(SettingsRepository::SITE_DESCRIPTION, $description);
        }

        $this->handleImage(
            $request->file('site_logo'),
            (bool) ($data['remove_site_logo'] ?? false),
            SettingsRepository::SITE_LOGO,
        );

        $this->handleImage(
            $request->file('favicon'),
            (bool) ($data['remove_favicon'] ?? false),
            SettingsRepository::FAVICON,
        );

        $this->audit->log(AuditEvent::SETTINGS_CHANGED, $request->user(), null, [
            'fields' => ['site_name', 'site_description', 'timezone', 'site_logo', 'favicon'],
        ]);

        $this->adminNotifications->recordNotificationConfigChanged(
            'settings_updated',
            'System settings were updated.',
            AdminNotificationService::LINK_SETTINGS,
            null,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('System settings saved.'));
    }

    /**
     * Replace, remove or leave a stored image untouched.
     *
     * The uploaded file is stored on the public disk under a generated name
     * (`store()` hashes the name), so the original filename and any path
     * traversal in it are discarded. The previous file is deleted only after a
     * successful store.
     */
    private function handleImage(?UploadedFile $file, bool $remove, string $key): void
    {
        $current = $this->settings->get($key);

        if ($file !== null) {
            $path = $file->store(self::BRANDING_DIR, 'public');

            if (is_string($path)) {
                $this->deleteIfStored($current);
                $this->settings->set($key, $path);
            }

            return;
        }

        if ($remove) {
            $this->deleteIfStored($current);
            $this->settings->forget($key);
        }
    }

    private function deleteIfStored(mixed $path): void
    {
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Valid PHP timezones grouped by region for an optgroup select.
     *
     * @return array<string, list<string>>
     */
    private function groupedTimezones(): array
    {
        $grouped = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $region = str_contains($identifier, '/') ? explode('/', $identifier)[0] : 'Other';
            $grouped[$region][] = $identifier;
        }

        ksort($grouped);

        return $grouped;
    }
}
