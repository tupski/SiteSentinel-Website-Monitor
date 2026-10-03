<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate new notification channel (Phase 7 Admin UI).
 *
 * Flat inputs map to notification_channels.config JSON in controller.
 * Secrets validated as nullable string; never echoed in errors.
 */
final class StoreNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Textarea posts one string; split lines/commas into array for validation.
        if (is_string($this->input('email_recipients_text')) && ! $this->has('email_recipients')) {
            $parts = preg_split('/[\r\n,]+/', (string) $this->input('email_recipients_text')) ?: [];
            $recipients = array_values(array_filter(array_map('trim', $parts)));
            $this->merge(['email_recipients' => $recipients]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:email,telegram'],
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'secret_ref' => ['nullable', 'string', 'max:2000'],
            'min_severity' => ['nullable', 'string', 'in:WARNING,CRITICAL'],

            // Email config fields.
            'email_recipients' => ['required_if:type,email', 'nullable', 'array', 'max:20'],
            'email_recipients.*' => ['email:rfc', 'max:255'],
            'email_host' => ['required_if:type,email', 'nullable', 'string', 'max:255'],
            'email_port' => ['required_if:type,email', 'nullable', 'integer', 'between:1,65535'],
            'email_username' => ['nullable', 'string', 'max:255'],
            'email_encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'email_from_address' => ['required_if:type,email', 'nullable', 'email:rfc', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:255'],

            // Telegram config fields.
            'telegram_chat_id' => ['required_if:type,telegram', 'nullable', 'string', 'max:64'],
            'telegram_thread_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Channel type is invalid.',
            'email_recipients.required_if' => 'At least one recipient is required.',
            'email_host.required_if' => 'SMTP host is required.',
            'email_from_address.required_if' => 'From address is required.',
            'telegram_chat_id.required_if' => 'Chat ID is required.',
        ];
    }
}
