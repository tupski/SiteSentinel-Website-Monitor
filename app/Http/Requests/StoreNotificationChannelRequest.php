<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelConfig;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate new notification channel (Phase 7 Admin UI, Plan S3).
 *
 * Flat inputs map to notification_channels.config JSON in controller.
 * Secrets validated as nullable string; never echoed in errors. Config rules
 * are type-aware and shared with update/test via ValidatesChannelConfig, so a
 * Telegram submission carrying email fields (or vice versa) is rejected.
 */
final class StoreNotificationChannelRequest extends FormRequest
{
    use ValidatesChannelConfig;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareChannelConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ] + $this->channelConfigRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->channelConfigMessages();
    }
}
