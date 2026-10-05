<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update validation for a status page (ADR-031, STATUS-PAGE.md §11.4).
 *
 * Mirrors the Phase 8 singleton rules (public confirmation, password fail-closed,
 * slug alpha_dash) applied per page.
 */
final class StatusPageRequest extends FormRequest
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
        $minPassword = max(12, (int) settings(SettingsRepository::AUTH_MIN_PASSWORD_LENGTH));

        /** @var StatusPage|null $page */
        $page = $this->route('status_page');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:191', 'alpha_dash',
                Rule::unique('status_pages', 'slug')->ignore($page?->id),
            ],
            'visibility_mode' => ['required', Rule::in([
                StatusPage::MODE_PRIVATE,
                StatusPage::MODE_PUBLIC,
                StatusPage::MODE_PASSWORD_PROTECTED,
            ])],
            'password' => ['nullable', 'string', 'min:'.$minPassword, 'max:255'],
            'password_confirmation' => ['nullable', 'string'],
            'clear_password' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'confirm_public' => ['nullable', 'boolean'],
            'published' => ['nullable', 'array'],
            'published.*' => ['integer', 'exists:websites,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $mode = (string) ($data['visibility_mode'] ?? '');

            if ($mode === StatusPage::MODE_PUBLIC && empty($data['confirm_public'])) {
                $v->errors()->add('confirm_public', 'Going public requires explicit confirmation.');
            }

            if ($mode === StatusPage::MODE_PASSWORD_PROTECTED) {
                $clear = ! empty($data['clear_password']);
                $pw = (string) ($data['password'] ?? '');

                /** @var StatusPage|null $page */
                $page = $this->route('status_page');
                $existing = $page?->password_hash;

                if (($clear || $pw === '') && empty($existing)) {
                    $v->errors()->add('password', 'Password Protected mode requires a password.');
                }

                if ($pw !== '' && $pw !== (string) ($data['password_confirmation'] ?? '')) {
                    $v->errors()->add('password_confirmation', 'Password confirmation does not match.');
                }
            }
        });
    }
}
