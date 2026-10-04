<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Models\StatusPageSetting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStatusPageSettingsRequest extends FormRequest
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
        $minPassword = max(12, (int) config('sentinel.auth.min_password_length', 12));

        return [
            'visibility_mode' => ['required', Rule::in([
                StatusPageSetting::MODE_PRIVATE,
                StatusPageSetting::MODE_PUBLIC,
                StatusPageSetting::MODE_PASSWORD_PROTECTED,
            ])],
            'password' => ['nullable', 'string', 'min:'.$minPassword, 'max:255'],
            'password_confirmation' => ['nullable', 'string'],
            'clear_password' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:191', 'alpha_dash'],
            'branding.title' => ['nullable', 'string', 'max:255'],
            'branding.message' => ['nullable', 'string', 'max:2000'],
            'branding.footer' => ['nullable', 'string', 'max:1000'],
            'published' => ['nullable', 'array'],
            'published.*' => ['integer'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['nullable', 'string', 'max:255'],
            'history_enabled' => ['nullable', 'boolean'],
            'confirm_public' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $mode = (string) ($data['visibility_mode'] ?? '');

            if ($mode === StatusPageSetting::MODE_PUBLIC && empty($data['confirm_public'])) {
                $v->errors()->add('confirm_public', 'Going public requires explicit confirmation.');
            }

            if ($mode === StatusPageSetting::MODE_PASSWORD_PROTECTED) {
                $clear = ! empty($data['clear_password']);
                $pw = (string) ($data['password'] ?? '');
                $existing = StatusPage::resolveDefault()->password_hash;
                if ($clear || ($pw === '' && empty($existing))) {
                    if ($pw === '') {
                        $v->errors()->add('password', 'Password Protected mode requires a password.');
                    }
                }
                if ($pw !== '' && $pw !== (string) ($data['password_confirmation'] ?? '')) {
                    $v->errors()->add('password_confirmation', 'Password confirmation does not match.');
                }
            }
        });
    }
}
