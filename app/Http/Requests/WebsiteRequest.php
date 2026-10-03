<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Security\SsrfUrlValidator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base rules for storing and updating a monitored website (PLAN.md Phase 3).
 */
abstract class WebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'check_interval_seconds' => ['required', 'integer', 'min:60'],
            'timeout_seconds' => ['required', 'integer', 'min:3', 'max:30'],
            'expected_status' => ['required', 'integer', 'between:100,599'],
            'expected_title' => ['nullable', 'string', 'max:512'],
            'expected_final_domain' => ['nullable', 'string', 'max:255'],
            'follow_redirects' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string'],
            'monitor_ssl' => ['sometimes', 'boolean'],
            'monitor_redirects' => ['sometimes', 'boolean'],
            'monitor_content' => ['sometimes', 'boolean'],
            'monitor_security' => ['sometimes', 'boolean'],
            'channel_ids' => ['nullable', 'array', 'max:100'],
            'channel_ids.*' => ['integer', 'exists:notification_channels,id'],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        return array_merge($validated, [
            'scheme' => $this->input('scheme'),
            'host' => $this->input('host'),
            'url' => $this->input('url'),
        ]);
    }

    protected function passedValidation(): void
    {
        $url = $this->input('url');

        if ($url !== null && $url !== '') {
            $result = SsrfUrlValidator::validate((string) $url);
            $this->merge($result);
        }
    }
}
