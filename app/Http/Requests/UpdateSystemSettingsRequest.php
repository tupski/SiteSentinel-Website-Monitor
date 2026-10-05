<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Settings\SettingsRepository;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin system settings form (ADR-035, ADR-043).
 *
 * Rules are derived from the {@see SettingsRepository} key registry, so a new
 * setting is added in exactly one place. The timezone is validated against the
 * full PHP identifier list. Uploads are checked by mime type, extension and
 * size; the filename is never trusted (the controller stores under a generated
 * name).
 *
 * Infrastructure secrets (APP_KEY, DB, SMTP, API, queue credentials) are NOT in
 * the registry and can never be written through this endpoint.
 */
final class UpdateSystemSettingsRequest extends FormRequest
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
        $rules = [
            'site_name' => ['required', 'string', 'max:255'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'site_logo' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,svg,webp',
                'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp',
                'max:2048',
            ],
            'favicon' => [
                'nullable',
                'file',
                'mimes:ico,png,svg',
                'mimetypes:image/vnd.microsoft.icon,image/x-icon,image/png,image/svg+xml',
                'max:256',
            ],
            'remove_site_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];

        // Numeric/operational settings: the registry owns their bounds.
        foreach (SettingsRepository::definitions() as $definition) {
            $field = $definition['field'];

            if (in_array($field, ['site_name', 'site_description', 'timezone', 'site_logo', 'favicon'], true)) {
                continue;
            }

            $rules[$field] = $definition['rules'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'site_name.required' => 'The site name is required.',
            'site_name.max' => 'The site name may not be longer than 255 characters.',
            'site_description.max' => 'The site description may not be longer than 500 characters.',
            'timezone.required' => 'The timezone is required.',
            'timezone.in' => 'Select a valid timezone.',
            'site_logo.mimes' => 'The logo must be a PNG, JPG, SVG or WebP image.',
            'site_logo.mimetypes' => 'The logo must be a PNG, JPG, SVG or WebP image.',
            'site_logo.max' => 'The logo may not be larger than 2 MB.',
            'favicon.mimes' => 'The favicon must be an ICO, PNG or SVG file.',
            'favicon.mimetypes' => 'The favicon must be an ICO, PNG or SVG file.',
            'favicon.max' => 'The favicon may not be larger than 256 KB.',
        ];
    }
}
