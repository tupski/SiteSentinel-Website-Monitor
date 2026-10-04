<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating the authenticated admin's own profile (Phase 5).
 *
 * The whitelist is deliberately explicit: only `name` and `email` are
 * accepted. `role` / `is_active` / `password` are NOT part of this request,
 * so privilege escalation through the profile form is structurally
 * impossible (the User model does not mass-assign them either).
 */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'max:255', 'email',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Your name is required.',
            'email.required' => 'Your email address is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address is already in use.',
        ];
    }

    /**
     * Normalise the email to the lower-case form the login flow matches on
     * (see LoginController::throttleKey / audit lookups).
     */
    protected function passedValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
