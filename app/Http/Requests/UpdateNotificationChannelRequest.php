<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelConfig;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate notification channel update (Phase 7 Admin UI, Plan S3).
 *
 * Same shape as store; secret empty means preserve existing. Config rules are
 * type-aware and shared with store/test via ValidatesChannelConfig.
 */
final class UpdateNotificationChannelRequest extends FormRequest
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
