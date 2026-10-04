<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelConfig;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate an unsaved channel config for a "send test" action (Plan S3).
 *
 * No `name`/`enabled` — the test never persists a channel. Type-aware rules
 * are shared with store/update via ValidatesChannelConfig so a test proves the
 * same config the save would accept.
 */
final class TestNotificationChannelRequest extends FormRequest
{
    use ValidatesChannelConfig;

    public function authorize(): bool
    {
        // Admin + auth is enforced by the route group (EnsureAdmin), not here.
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
        return $this->channelConfigRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->channelConfigMessages();
    }
}
