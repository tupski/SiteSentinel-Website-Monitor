<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Admin profile management (PLAN.md Phase 5).
 *
 * The controller only ever operates on the authenticated user — it accepts no
 * user id, so an admin can never edit another account through this endpoint
 * (IDOR-safe by construction). Password values are verified and hashed
 * server-side and are never echoed, logged, or written to the audit trail.
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit
    ) {}

    public function edit(): View
    {
        /** @var User $user */
        $user = $this->authenticatedUser();

        return view('admin.profile.edit', compact('user'));
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Explicit whitelist: name + email only. `role` / `is_active` are not
        // mass-assignable on the model and are not accepted by the request, so
        // privilege escalation through this form is impossible.
        $user->fill($request->validated())->save();

        $this->audit->log('auth.profile.updated', $user, $user, [
            'fields' => ['name', 'email'],
        ]);

        return redirect()
            ->route('admin.profile.edit')
            ->with('status', __('Profile updated.'));
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), (string) $user->getAuthPassword())) {
            return back()
                ->withErrors(['current_password' => __('Your current password is incorrect.')])
                ->withInput($request->except(['current_password', 'password', 'password_confirmation']));
        }

        // `password` is cast to `hashed` on the User model, so assigning the
        // plain value hashes it exactly once with the app hasher.
        $user->forceFill(['password' => (string) $request->input('password')])->save();

        // Keep the current session valid (no re-authentication requirement is
        // documented for self-service changes) — never touch the session
        // middleware. Other sessions are left as-is; the reset flow is the only
        // path that revokes sessions (SECURITY.md §2.5/§2.9).
        $this->audit->log('auth.password.changed', $user, $user);

        return redirect()
            ->route('admin.profile.edit')
            ->with('status', __('Password changed.'));
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
