<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-page visibility decision gate (STATUS-PAGE.md §2, ARCHITECTURE.md §10).
 *
 * The unlock session is keyed per page (`status_unlock.{page_id}`): entering
 * the password for page A never unlocks page B (ADR-031).
 */
final class VisibilityGate
{
    /**
     * Session key holding the unlocked-at stamp for a given page id.
     */
    public static function unlockSessionKey(StatusPage $page): string
    {
        return 'status_unlock.'.$page->id;
    }

    /**
     * Session key holding the settings version stamp for a given page id.
     */
    public static function unlockVersionKey(StatusPage $page): string
    {
        return 'status_unlock.'.$page->id.'.version';
    }

    public function canView(Request $request, StatusPage $page): bool
    {
        if ($page->isPublic()) {
            return true;
        }

        if ($page->isPrivate()) {
            return (bool) Auth::check() && (bool) Auth::user()?->isAdmin();
        }

        return $this->isUnlocked($request, $page);
    }

    public function isUnlocked(Request $request, StatusPage $page): bool
    {
        if (! $page->isPasswordProtected()) {
            return false;
        }

        $unlockedAt = $request->session()->get(self::unlockSessionKey($page));
        if ($unlockedAt === null) {
            return false;
        }

        $stamped = (string) $request->session()->get(self::unlockVersionKey($page), '');
        $current = $this->versionStamp($page);

        return hash_equals($current, $stamped);
    }

    public function versionStamp(StatusPage $page): string
    {
        $updated = $page->updated_at?->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') ?? 'never';

        return 'v1:'.$updated;
    }

    public function hasUsablePassword(StatusPage $page): bool
    {
        return $page->hasUsablePassword();
    }
}
