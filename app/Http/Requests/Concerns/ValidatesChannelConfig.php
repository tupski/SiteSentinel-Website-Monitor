<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Shared notification-channel config validation (Plan S3).
 *
 * Used by Store/Update/Test channel requests so the type-aware rules live in
 * exactly one place. Type is authoritative: fields that do not belong to the
 * selected type are `prohibited_if` (rejected if a client tampers and sends
 * them) and required config is `required_if` the matching type. This keeps the
 * server strict even though the Alpine form only renders the relevant fields.
 */
trait ValidatesChannelConfig
{
    /**
     * Textarea posts one string; split lines/commas into an array so the
     * existing `email_recipients` array rules apply unchanged.
     */
    protected function prepareChannelConfig(): void
    {
        if (is_string($this->input('email_recipients_text')) && ! $this->has('email_recipients')) {
            $parts = preg_split('/[\r\n,]+/', (string) $this->input('email_recipients_text')) ?: [];
            $recipients = array_values(array_filter(array_map('trim', $parts)));
            $this->merge(['email_recipients' => $recipients]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function channelConfigRules(): array
    {
        return [
            'type' => ['required', 'string', 'in:email,telegram'],
            'secret_ref' => ['nullable', 'string', 'max:2000'],
            'min_severity' => ['nullable', 'string', 'in:WARNING,CRITICAL'],

            // Email config fields — required for email, forbidden otherwise.
            'email_recipients' => ['required_if:type,email', 'nullable', 'array', 'max:20', 'prohibited_if:type,telegram'],
            'email_recipients.*' => ['email:rfc', 'max:255'],
            'email_host' => ['required_if:type,email', 'nullable', 'string', 'max:255', 'prohibited_if:type,telegram'],
            'email_port' => ['required_if:type,email', 'nullable', 'integer', 'between:1,65535', 'prohibited_if:type,telegram'],
            'email_username' => ['nullable', 'string', 'max:255', 'prohibited_if:type,telegram'],
            'email_encryption' => ['nullable', 'string', 'in:tls,ssl,none', 'prohibited_if:type,telegram'],
            'email_from_address' => ['required_if:type,email', 'nullable', 'email:rfc', 'max:255', 'prohibited_if:type,telegram'],
            'email_from_name' => ['nullable', 'string', 'max:255', 'prohibited_if:type,telegram'],

            // Telegram config fields — required for telegram, forbidden otherwise.
            'telegram_chat_id' => ['required_if:type,telegram', 'nullable', 'string', 'max:64', 'prohibited_if:type,email'],
            'telegram_thread_id' => ['nullable', 'string', 'max:64', 'prohibited_if:type,email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function channelConfigMessages(): array
    {
        return [
            'type.in' => 'Channel type is invalid.',
            'email_recipients.required_if' => 'At least one recipient is required.',
            'email_host.required_if' => 'SMTP host is required.',
            'email_from_address.required_if' => 'From address is required.',
            'telegram_chat_id.required_if' => 'Chat ID is required.',
            'email_host.prohibited_if' => 'SMTP settings are only valid for email channels.',
            'email_recipients.prohibited_if' => 'Recipients are only valid for email channels.',
            'email_from_address.prohibited_if' => 'From address is only valid for email channels.',
            'telegram_chat_id.prohibited_if' => 'Chat ID is only valid for Telegram channels.',
            'telegram_thread_id.prohibited_if' => 'Topic thread ID is only valid for Telegram channels.',
        ];
    }
}
