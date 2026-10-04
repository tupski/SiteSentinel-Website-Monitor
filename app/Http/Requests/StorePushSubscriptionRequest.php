<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register a Web Push subscription (NOTIFICATIONS.md §7.3, ADR-032).
 *
 * The subscription material (endpoint/p256dh/auth) is validated as opaque
 * strings and never echoed back in errors (SECURITY.md §4).
 */
final class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:512'],
            'user_agent' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.required' => 'A push endpoint is required.',
            'keys.required' => 'Push subscription keys are required.',
            'keys.p256dh.required' => 'The push public key is required.',
            'keys.auth.required' => 'The push auth secret is required.',
        ];
    }
}
