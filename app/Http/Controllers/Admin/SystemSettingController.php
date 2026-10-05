<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SettingVersion;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Settings\SettingsRepository;
use App\Services\Settings\SettingsVersionService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin system settings (ADR-035, ADR-043).
 *
 * Exposes the registered settings — site identity, branding, timezone and the
 * operational tuning knobs (monitoring, detection, notifications, retention,
 * security). Infrastructure secrets (APP_KEY, DB, SMTP, API, queue credentials)
 * are NOT part of the registry and can never be written through this endpoint:
 * the request derives its rules from the registry and the repository rejects
 * anything not registered.
 *
 * Every save writes an immutable {@see SettingVersion} snapshot so an admin can
 * roll back; pull/rollback are audited separately from a normal save.
 */
final class SystemSettingController extends Controller
{
    /** Public-disk directory holding uploaded branding assets. */
    private const BRANDING_DIR = 'branding';

    /** Number of versions shown in the history table. */
    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SettingsVersionService $versions,
        private readonly AuditLogger $audit,
        private readonly AdminNotificationService $adminNotifications,
    ) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'values' => $this->settings->all(),
            'groups' => SettingsRepository::groups(),
            'definitions' => SettingsRepository::grouped(),
            'timezones' => $this->groupedTimezones(),
            'versions' => SettingVersion::query()
                ->with('author:id,name')
                ->orderByDesc('version')
                ->limit(self::HISTORY_LIMIT)
                ->get(),
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

        // Operational tuning knobs: persist every registered numeric field that
        // was submitted. Absent/empty values restore the config-derived default.
        foreach (SettingsRepository::definitions() as $key => $definition) {
            $field = $definition['field'];

            if (in_array($field, ['site_name', 'site_description', 'timezone', 'site_logo', 'favicon'], true)) {
                continue;
            }

            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === null || $value === '') {
                $this->settings->forget($key);
            } else {
                $this->settings->set($key, $value);
            }
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

        $version = $this->versions->snapshot(SettingVersion::SOURCE_SAVE, $request->user());

        $this->audit->log(AuditEvent::SETTINGS_CHANGED, $request->user(), null, [
            'fields' => $this->changedFieldNames($data),
            'version' => $version->version,
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
     * Pull update: reconcile the live settings with the configured upstream /
     * latest known snapshot. Never destroys data without first snapshotting the
     * current state (see {@see SettingsVersionService::pull()}).
     */
    public function pull(Request $request): RedirectResponse
    {
        $result = $this->versions->pull($request->user());

        $this->audit->log(AuditEvent::SETTINGS_PULLED, $request->user(), null, [
            'source' => $result['source'],
            'applied' => $result['applied'],
            'version' => $result['version']->version,
        ]);

        $this->adminNotifications->recordNotificationConfigChanged(
            'settings_pulled',
            'System settings were pulled/refreshed.',
            AdminNotificationService::LINK_SETTINGS,
            null,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('Pulled the latest settings (:source, :count applied).', [
                'source' => $result['source'],
                'count' => $result['applied'],
            ]));
    }

    /**
     * Roll back the live settings to a previous snapshot. The current state is
     * snapshotted first, so a rollback is itself reversible.
     */
    public function rollback(Request $request, SettingVersion $settingVersion): RedirectResponse
    {
        $result = $this->versions->rollbackTo($settingVersion, $request->user());

        $this->audit->log(AuditEvent::SETTINGS_ROLLED_BACK, $request->user(), null, [
            'from_version' => $result['before']->version,
            'to_version' => $settingVersion->version,
            'applied' => $result['applied'],
        ]);

        $this->adminNotifications->recordNotificationConfigChanged(
            'settings_rolled_back',
            'System settings were rolled back to v'.$settingVersion->version.'.',
            AdminNotificationService::LINK_SETTINGS,
            null,
            $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', __('Rolled back to version :version.', ['version' => $settingVersion->version]));
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
     * Field names present in the payload (for the audit metadata only — never
     * the values).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function changedFieldNames(array $data): array
    {
        return array_values(array_keys($data));
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
