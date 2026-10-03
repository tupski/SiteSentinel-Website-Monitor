<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPageSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Visibility decision gate (STATUS-PAGE.md §2, ARCHITECTURE.md §10).
 */
final class VisibilityGate
{
    public const UNLOCK_SESSION_KEY = 'status_page.unlocked_at';

    public const UNLOCK_VERSION_KEY = 'status_page.settings_updated_at';

    public function mode(): StatusPageSetting
    {
        return StatusPageSetting::singleton();
    }

    public function canView(Request $request, StatusPageSetting $settings): bool
    {
        if ($settings->isPublic()) {
            return true;
        }

        if ($settings->isPrivate()) {
            return (bool) Auth::check() && (bool) Auth::user()?->isAdmin();
        }

        return $this->isUnlocked($request, $settings);
    }

    public function isUnlocked(Request $request, StatusPageSetting $settings): bool
    {
        if (! $settings->isPasswordProtected()) {
            return false;
        }

        $unlockedAt = $request->session()->get(self::UNLOCK_SESSION_KEY);
        if ($unlockedAt === null) {
            return false;
        }

        $stamped = (string) $request->session()->get(self::UNLOCK_VERSION_KEY, '');
        $current = $this->versionStamp($settings);

        return hash_equals($current, $stamped);
    }

    public function versionStamp(StatusPageSetting $settings): string
    {
        $updated = $settings->updated_at?->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') ?? 'never';

        return 'v1:'.$updated;
    }

    public function hasUsablePassword(StatusPageSetting $settings): bool
    {
        $hash = (string) ($settings->password_hash ?? '');

        return $hash !== '';
    }
}
