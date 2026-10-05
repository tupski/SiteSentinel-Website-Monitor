<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the documented admin session timeouts (SECURITY.md §2.5, PLAN.md
 * Phase 9 checklist): idle timeout (default 30 min) and absolute timeout
 * (default 8 h) independent of activity.
 *
 * The session payload carries two anchors:
 *  - `auth.login_at`   — set once, on the first authenticated request; the
 *                        absolute-timeout origin.
 *  - `auth.last_seen_at` — refreshed every request; the idle-timeout origin.
 *
 * Exceeding either window logs the user out and invalidates the session store
 * (fixation/invalid-session defence). Values come from `config/sentinel.php`
 * `auth.idle_timeout_minutes` / `auth.absolute_timeout_minutes`.
 */
final class EnforceSessionTimeouts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        // Read through the settings accessor (ADR-035, ADR-043) so an admin edit
        // on the Settings page is applied; absent rows fall back to config.
        $idleMinutes = max(1, (int) settings(SettingsRepository::AUTH_IDLE_TIMEOUT));
        $absoluteMinutes = max(1, (int) settings(SettingsRepository::AUTH_ABSOLUTE_TIMEOUT));

        $now = now();
        $loginAt = $request->session()->get('auth.login_at');
        $lastSeenAt = $request->session()->get('auth.last_seen_at');

        // First authenticated request in this session: seed both anchors.
        if (! is_string($loginAt) || $loginAt === '') {
            $request->session()->put('auth.login_at', $now->toIso8601String());
            $request->session()->put('auth.last_seen_at', $now->toIso8601String());

            return $next($request);
        }

        // Carbon 3 `diffInMinutes` is signed; compute elapsed time from the
        // anchor forward to "now" so the result is a positive duration.
        $idleExpired = is_string($lastSeenAt)
            && $lastSeenAt !== ''
            && Carbon::parse($lastSeenAt)->diffInMinutes($now) > $idleMinutes;

        $absoluteExpired = Carbon::parse($loginAt)->diffInMinutes($now) > $absoluteMinutes;

        if ($idleExpired || $absoluteExpired) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Session expired.'], 401)
                : redirect()->route('login');
        }

        $request->session()->put('auth.last_seen_at', $now->toIso8601String());

        return $next($request);
    }
}
