<?php

declare(strict_types=1);

namespace App\Services\StatusPage;

use App\Models\StatusPage;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Password unlock for a single status page (STATUS-PAGE.md §3).
 *
 * The unlock session key is per page (`status_unlock.{page_id}`), so a correct
 * password for page A never unlocks page B (ADR-031).
 */
final class StatusPageUnlockService
{
    public function __construct(
        private readonly VisibilityGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(Request $request, StatusPage $page, string $password): bool
    {
        if (! $page->isPasswordProtected()) {
            return false;
        }

        $hash = (string) ($page->password_hash ?? '');
        if ($hash === '') {
            $this->audit->log('status_page.unlock.failed', null, $page, ['reason' => 'no_password_configured']);

            return false;
        }

        if (! Hash::check($password, $hash)) {
            $this->audit->log('status_page.unlock.failed', null, $page, ['reason' => 'bad_password']);

            return false;
        }

        $request->session()->put(VisibilityGate::unlockSessionKey($page), now('UTC')->format('Y-m-d\TH:i:s\Z'));
        $request->session()->put(VisibilityGate::unlockVersionKey($page), $this->gate->versionStamp($page));

        RateLimiter::clear($this->throttleKey($request));

        return true;
    }

    public function lock(Request $request, StatusPage $page): void
    {
        $request->session()->forget([
            VisibilityGate::unlockSessionKey($page),
            VisibilityGate::unlockVersionKey($page),
        ]);
    }

    public function throttleKey(Request $request): string
    {
        $sessionId = (string) ($request->session()->getId() ?? 'guest');

        return 'status-unlock:'.$request->ip().':'.$sessionId;
    }
}
