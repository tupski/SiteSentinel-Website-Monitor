<?php

declare(strict_types=1);

namespace App\Http\Requests;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin system settings form (ADR-035).
 *
 * The accepted key set is a fixed whitelist — `site_name`, `site_description`,
 * `site_logo`, `favicon`, `timezone` — so arbitrary keys (and any attempt to
 * smuggle an infrastructure secret such as `app_key` or `mail_password`) are
 * rejected by validation rather than silently ignored. Uploads are checked by
 * mime type, extension and size; the filename is never trusted (the controller
 * stores under a generated name).
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
        $allowedLogoExtensions = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        $allowedFaviconExtensions = ['ico', 'png', 'svg'];

        return [
            'site_name' => ['required', 'string', 'max:255'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'site_logo' => [
                'nullable',
                'file',
                'mimes:'.implode(',', $allowedLogoExtensions),
                'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp',
                'max:2048',
            ],
            'favicon' => [
                'nullable',
                'file',
                'mimes:'.implode(',', $allowedFaviconExtensions),
                'mimetypes:image/vnd.microsoft.icon,image/x-icon,image/png,image/svg+xml',
                'max:256',
            ],
            'remove_site_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];
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
