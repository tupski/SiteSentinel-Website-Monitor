<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Session login at `/` (PLAN.md Phase 2, SECURITY.md §2.1–2.4).
 *
 * Throttle model (§2.4):
 *  - soft limit: 5 failed attempts / 15 min per (email + IP) → HTTP 429
 *  - lockout:    10 failed attempts / 30 min per key → 15 min lock (same 429)
 *  - success clears the counter; failures are audited.
 * Failure messaging is generic to resist account enumeration.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit
    ) {}

    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function login(Request $request): Response|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = $this->throttleKey($request);
        $auth = config('sentinel.auth');
        $lockKey = $key.':locked';

        // Hard lock: 10 failures / 30 min → identity locked for 15 min (§2.4).
        // A dedicated marker carries the exact lock duration, independent of
        // the counter's decay window.
        if (RateLimiter::tooManyAttempts($key.':lockout', (int) $auth['lockout_threshold'])
            && ! RateLimiter::tooManyAttempts($lockKey, 1)) {
            RateLimiter::hit($lockKey, ((int) $auth['lockout_duration_minutes']) * 60);
        }

        if (RateLimiter::tooManyAttempts($lockKey, 1)) {
            $this->auditLockedAttempt($request);

            return $this->throttledResponse(
                RateLimiter::availableIn($lockKey)
            );
        }

        // Soft limit: 5 failures / 15 min → 429 (does not trigger lockout)
        if (RateLimiter::tooManyAttempts($key.':soft', (int) $auth['max_login_attempts'])) {
            $this->auditLockedAttempt($request);

            return $this->throttledResponse(
                RateLimiter::availableIn($key.':soft')
            );
        }

        $remember = false; // remember-me disabled at MVP (SECURITY.md §2.7)

        // Attempt: only active admin accounts may sign in.
        $attempted = Auth::attempt(
            array_merge($credentials, ['is_active' => true]),
            $remember
        );

        if (! $attempted) {
            $this->recordFailure($key, $request);

            // Generic message — no account enumeration (SECURITY.md §2.4)
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Success: clear all counters (SECURITY.md §2.4 "Reset")
        RateLimiter::clear($key.':soft');
        RateLimiter::clear($key.':lockout');
        RateLimiter::clear($key.':locked');

        $request->session()->regenerate(); // fixation defence (§2.5)

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->log(AuditEvent::AUTH_LOGIN_SUCCESS, $user, $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $this->audit->log(AuditEvent::AUTH_LOGOUT, $user, $user);

        Auth::guard('web')->logout();

        // FR-07 / SECURITY.md §2.5: destroy the server-side session row,
        // invalidate the store entry, and regenerate the CSRF token.
        $sessionId = $request->session()->getId();
        $request->session()->invalidate();
        optional($request->session()->getHandler())->destroy($sessionId);
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Throttle key: email + source IP (SECURITY.md §2.4).
     */
    private function throttleKey(Request $request): string
    {
        return 'login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }

    /**
     * HTTP 429 response with the generic throttle message (SECURITY.md §2.4).
     *
     * Rendered inline (redirect with errors would show 302): browsers get a
     * small HTML page, JSON clients get the structured error.
     */
    private function throttledResponse(int $retryAfter): Response
    {
        $message = trans('auth.throttle', ['seconds' => $retryAfter]);

        /** @var Response $response */
        $response = response()
            ->view('errors.429', ['message' => $message, 'retryAfter' => $retryAfter], 429);

        return $response;
    }

    private function recordFailure(string $key, Request $request): void
    {
        $auth = config('sentinel.auth');

        RateLimiter::hit(
            $key.':soft',
            ((int) $auth['soft_limit_window_minutes']) * 60
        );
        RateLimiter::hit(
            $key.':lockout',
            ((int) $auth['lockout_window_minutes']) * 60
        );

        /** @var User|null $user */
        $user = User::query()
            ->where('email', mb_strtolower((string) $request->input('email')))
            ->first();

        $this->audit->log(AuditEvent::AUTH_LOGIN_FAILURE, $user, $user, [
            'throttle_key' => $key,
            'attempts_soft' => RateLimiter::attempts($key.':soft'),
            'attempts_lockout' => RateLimiter::attempts($key.':lockout'),
        ]);
    }

    private function auditLockedAttempt(Request $request): void
    {
        /** @var User|null $user */
        $user = User::query()
            ->where('email', mb_strtolower((string) $request->input('email')))
            ->first();

        $this->audit->log(AuditEvent::AUTH_LOGIN_THROTTLED, $user, $user, [
            'ip' => $request->ip(),
        ]);
    }
}
