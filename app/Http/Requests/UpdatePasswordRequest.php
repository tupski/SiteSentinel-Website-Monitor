<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Form request for changing the authenticated admin's own password (Phase 5).
 *
 * Rules mirror the project password policy (SECURITY.md §2.3): minimum length
 * from `config('sentinel.auth.min_password_length')` (12 by default), always
 * `confirmed`, and never equal to the password currently in use. The current
 * password is verified with `Hash::check` in the controller — a mismatch is
 * rejected as a field error, never as an exception page.
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * Minimum length is always at least the documented floor (12), so a
     * lowered env override can never weaken the policy.
     */
    private function minimumLength(): int
    {
        return max(12, (int) config('sentinel.auth.min_password_length', 12));
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                Password::min($this->minimumLength()),
                'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->currentPasswordMatches() && $value === $this->input('current_password')) {
                        $fail('The new password must be different from your current password.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'password.required' => 'Enter a new password.',
            'password.min' => 'The new password must be at least :min characters.',
            'password.confirmed' => 'The new password confirmation does not match.',
            'password_confirmation.required' => 'Confirm the new password.',
        ];
    }

    /**
     * True when the supplied `current_password` matches the live hash.
     */
    private function currentPasswordMatches(): bool
    {
        $user = $this->user();
        $current = $this->input('current_password');

        return $user !== null
            && is_string($current)
            && Hash::check($current, (string) $user->getAuthPassword());
    }
}
