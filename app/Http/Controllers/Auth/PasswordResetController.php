<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Password reset flow (PLAN.md Phase 2, SECURITY.md §2.9).
 *
 * - tokens live 30 minutes, single use, hashed at rest in password_reset_tokens
 * - responses are identical whether or not the email exists (enumeration resistance)
 * - on success: all other sessions for the user are invalidated (§2.5)
 */
final class PasswordResetController extends Controller
{
    /**
     * Token lifetime in minutes (SECURITY.md §2.9).
     */
    private const TOKEN_TTL_MINUTES = 30;

    public function __construct(
        private readonly AuditLogger $audit
    ) {}

    public function requestForm(): View
    {
        return view('auth.passwords.email');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        /** @var User|null $user */
        $user = User::query()->where('email', mb_strtolower((string) $request->input('email')))->first();

        if ($user !== null && $user->isActive()) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            $resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);

            // MVP transport is the configured mailer (log driver in dev). The
            // reset link is delivered only to the registered address (§2.9).
            Mail::to($user->email)
                ->send(new PasswordResetMail($resetUrl, self::TOKEN_TTL_MINUTES));

            $this->audit->log(AuditEvent::AUTH_PASSWORD_RESET_REQUESTED, $user, $user);
        } else {
            // Audited without user linkage; still no behavioral difference outside the log.
            $this->audit->log('auth.password_reset_requested_unknown', null, null, [
                'email' => mb_strtolower((string) $request->input('email')),
            ]);
        }

        // Identical response either way (enumeration resistance, §2.9)
        return back()->with('status', __('passwords.sent'));
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:'.(int) config('sentinel.auth.min_password_length'), 'confirmed'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        $record = $user !== null
            ? DB::table('password_reset_tokens')->where('email', $user->email)->first()
            : null;

        // DB::table rows return raw strings; cast the timestamp before comparing
        $recordCreatedAt = $record?->created_at !== null
            ? Carbon::parse($record->created_at)
            : null;

        $valid = $record !== null
            && Hash::check($data['token'], (string) $record->token)
            && $recordCreatedAt !== null
            && $recordCreatedAt->gt(now()->subMinutes(self::TOKEN_TTL_MINUTES));

        if (! $valid || $user === null) {
            $this->audit->log('auth.password_reset_failed', $user, $user, [
                'reason' => $record === null ? 'no_token' : ($valid ? '' : 'token_invalid_or_expired'),
            ]);

            return back()->withErrors(['email' => __('passwords.token')]);
        }

        // Single use: delete before anything else (§2.9)
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $user->forceFill(['password' => $data['password']])->save(); // hashed cast

        // Invalidate every other session for this user (SECURITY.md §2.5)
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        // Audit with actor + timestamp (AC-2-06)
        $this->audit->log(AuditEvent::AUTH_PASSWORD_RESET_COMPLETED, $user, $user);

        // Recovery completes a login-equivalent identity proof, but we still
        // require a fresh explicit login (no auto-login surprise).
        Auth::guard('web')->logout();

        return redirect()->route('login')->with('status', __('passwords.reset'));
    }
}
