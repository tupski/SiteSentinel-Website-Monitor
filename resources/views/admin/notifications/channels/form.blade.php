<x-admin-layout>
    <x-slot name="title">{{ $channel ? __('Edit channel') : __('Add channel') }} — {{ __('Notification') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <nav class="mb-4 text-sm" aria-label="{{ __('Notification sections') }}">
            <a href="{{ route('admin.notifications.index') }}" class="text-text-muted underline hover:text-text">{{ __('Notification') }}</a>
            <span class="mx-1 text-text-subtle" aria-hidden="true">/</span>
            <span class="text-text">{{ $channel ? __('Edit channel') : __('Add channel') }}</span>
        </nav>

        <h1 class="text-2xl font-bold tracking-tight text-text">{{ $channel ? __('Edit channel') : __('Add channel') }}</h1>

        @if ($errors->any())
            <x-ui.alert variant="danger" class="mt-4">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
                @error('config')<p class="mt-1">{{ $message }}</p>@enderror
            </x-ui.alert>
        @endif

        <form method="POST"
              action="{{ $channel ? route('admin.notifications.update', $channel) : route('admin.notifications.store') }}"
              class="mt-6 space-y-6 rounded-lg border border-border bg-surface-elevated p-6 shadow-sm"
              data-turbo="true"
              x-data="{ type: @js(old('type', $channel?->type ?? 'email')) }">
            @csrf
            @if ($channel)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <x-form.field name="type" :label="__('Type')" required
                              hint="{{ __('Email or Telegram.') }}"
                              help="{{ __('Choose the transport for this channel. Only the settings relevant to the selected type are shown, and the server rejects fields that belong to the other type.') }}">
                    <x-ui.select id="type" name="type" required x-model="type"
                            aria-describedby="type-hint" :invalid="$errors->has('type')">
                        <option value="email">{{ __('Email (SMTP)') }}</option>
                        <option value="telegram">{{ __('Telegram (Bot API)') }}</option>
                    </x-ui.select>
                </x-form.field>

                <x-form.field name="name" :label="__('Name')" required
                              hint="{{ __('A short label shown in listings and alerts.') }}"
                              help="{{ __('Used only inside SiteSentinel. Choose something an operator will recognise, e.g. “Ops email”. Maximum 255 characters.') }}">
                    <x-ui.input id="name" name="name" type="text" :value="old('name', $channel?->name)" required maxlength="255"
                           aria-describedby="name-hint" :invalid="$errors->has('name')" />
                </x-form.field>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <span class="inline-flex items-center gap-2">
                    <input type="hidden" name="enabled" value="0">
                    <x-ui.checkbox name="enabled" value="1" :checked="(bool) old('enabled', $channel?->enabled ?? true)" />
                    <span class="text-sm text-text-muted">{{ __('Enabled') }}</span>
                </span>

                <x-form.field name="min_severity" :label="__('Minimum severity')"
                              hint="{{ __('WARNING or CRITICAL.') }}"
                              help="{{ __('Only events at or above this severity are delivered. CRITICAL delivers escalations but suppresses routine WARNING incidents.') }}">
                    <x-ui.select id="min_severity" name="min_severity"
                            aria-describedby="min_severity-hint">
                        <option value="WARNING" @selected(old('min_severity', ($config['min_severity'] ?? 'WARNING')) === 'WARNING')>WARNING</option>
                        <option value="CRITICAL" @selected(old('min_severity', ($config['min_severity'] ?? '')) === 'CRITICAL')>CRITICAL</option>
                    </x-ui.select>
                </x-form.field>
            </div>

            {{-- Email-only settings. Hidden + disabled when Telegram is selected so
                 no irrelevant field is ever submitted (Plan S3). --}}
            <fieldset class="rounded border border-border p-4" x-show="type === 'email'" x-cloak>
                <legend class="px-2 text-sm font-medium text-text">{{ __('Email settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <x-form.field name="email_recipients" id="email_recipients" :label="__('Recipients (one per line)')" required
                                      hint="{{ __('Up to 20 addresses.') }}"
                                      help="{{ __('One recipient per line (or comma-separated). These addresses receive the alert emails. Maximum 20 recipients; each must be a valid email address.') }}">
                            <x-ui.textarea id="email_recipients" name="email_recipients_text" :rows="2" placeholder="ops@example.com"
                                      x-bind:disabled="type !== 'email'"
                                      aria-describedby="email_recipients-hint" :invalid="$errors->has('email_recipients')">{{ old('email_recipients_text', isset($config['recipients']) ? implode("\n", (array) $config['recipients']) : '') }}</x-ui.textarea>
                        </x-form.field>
                        @error('email_recipients.*')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>

                    <x-form.field name="email_host" :label="__('SMTP host')" required
                                  hint="{{ __('e.g. smtp.example.com') }}"
                                  help="{{ __('The SMTP server hostname used to send alert email. Required for email channels.') }}">
                        <x-ui.input id="email_host" name="email_host" type="text" :value="old('email_host', $config['host'] ?? '')"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_host-hint" :invalid="$errors->has('email_host')" />
                    </x-form.field>

                    <x-form.field name="email_port" :label="__('SMTP port')" required
                                  hint="{{ __('Usually 587 (tls) or 465 (ssl).') }}"
                                  help="{{ __('The TCP port of the SMTP server. Must be between 1 and 65535. Port 587 with tls is the common default.') }}">
                        <x-ui.input id="email_port" name="email_port" type="number" min="1" max="65535" :value="old('email_port', $config['port'] ?? 587)"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_port-hint" :invalid="$errors->has('email_port')" />
                    </x-form.field>

                    <x-form.field name="email_username" :label="__('SMTP username')"
                                  hint="{{ __('Optional.') }}"
                                  help="{{ __('Username for SMTP authentication, if the server requires it. The password is supplied in the secret field below.') }}">
                        <x-ui.input id="email_username" name="email_username" type="text" :value="old('email_username', $config['username'] ?? '')" autocomplete="off"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_username-hint" />
                    </x-form.field>

                    <x-form.field name="email_encryption" :label="__('Encryption')"
                                  hint="{{ __('tls, ssl or none.') }}"
                                  help="{{ __('Transport encryption for the SMTP connection. Production rejects “none”; use tls (STARTTLS) or ssl (implicit TLS).') }}">
                        <x-ui.select id="email_encryption" name="email_encryption" x-bind:disabled="type !== 'email'"
                                aria-describedby="email_encryption-hint">
                            <option value="tls" @selected(old('email_encryption', ($config['encryption'] ?? 'tls')) === 'tls')>tls</option>
                            <option value="ssl" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'ssl')>ssl</option>
                            <option value="none" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'none')>none</option>
                        </x-ui.select>
                    </x-form.field>

                    <x-form.field name="email_from_address" :label="__('From address')" required
                                  hint="{{ __('Sender address shown to recipients.') }}"
                                  help="{{ __('The envelope/From address alert emails are sent from. Must be a valid email address; many SMTP servers require it to be a domain you control.') }}">
                        <x-ui.input id="email_from_address" name="email_from_address" type="email" :value="old('email_from_address', $config['from_address'] ?? '')"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_from_address-hint" :invalid="$errors->has('email_from_address')" />
                    </x-form.field>

                    <x-form.field name="email_from_name" :label="__('From name')"
                                  hint="{{ __('Optional display name.') }}"
                                  help="{{ __('Friendly sender name shown alongside the From address, e.g. “SiteSentinel”.') }}">
                        <x-ui.input id="email_from_name" name="email_from_name" type="text" :value="old('email_from_name', $config['from_name'] ?? 'SiteSentinel')"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_from_name-hint" />
                    </x-form.field>
                </div>
            </fieldset>

            {{-- Telegram-only settings. Hidden + disabled when Email is selected. --}}
            <fieldset class="rounded border border-border p-4" x-show="type === 'telegram'" x-cloak>
                <legend class="px-2 text-sm font-medium text-text">{{ __('Telegram settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-form.field name="telegram_chat_id" :label="__('Chat ID')" required
                                  hint="{{ __('Numeric chat or channel id.') }}"
                                  help="{{ __('The Telegram chat/channel the bot posts to. Required for Telegram channels. The bot must be a member of that chat.') }}">
                        <x-ui.input id="telegram_chat_id" name="telegram_chat_id" type="text" :value="old('telegram_chat_id', $config['chat_id'] ?? '')" autocomplete="off"
                               x-bind:disabled="type !== 'telegram'"
                               aria-describedby="telegram_chat_id-hint" :invalid="$errors->has('telegram_chat_id')" />
                    </x-form.field>

                    <x-form.field name="telegram_thread_id" :label="__('Topic thread ID (optional)')"
                                  hint="{{ __('For forum topics only.') }}"
                                  help="{{ __('Optional. Set only when posting into a specific topic of a Telegram forum group; leave blank otherwise.') }}">
                        <x-ui.input id="telegram_thread_id" name="telegram_thread_id" type="text" :value="old('telegram_thread_id', $config['message_thread_id'] ?? '')" autocomplete="off"
                               x-bind:disabled="type !== 'telegram'"
                               aria-describedby="telegram_thread_id-hint" />
                    </x-form.field>
                </div>
            </fieldset>

            <x-form.field name="secret_ref" :label="__('Secret (SMTP password or bot token)')"
                          :hint="$hasSecret ? __('A secret is stored. Leave empty to keep it.') : __('Required to deliver alerts.')"
                          help="{{ __('The SMTP password (email) or Telegram bot token. Stored encrypted at rest, never shown again, and never logged. On an existing channel, leave blank to keep the current secret.') }}">
                <x-ui.input id="secret_ref" name="secret_ref" type="password" value="" autocomplete="new-password"
                       placeholder="{{ $hasSecret ? __('Leave empty to keep existing secret') : __('Required') }}"
                       aria-describedby="secret_ref-hint" :invalid="$errors->has('secret_ref')" />
            </x-form.field>

            <div class="flex flex-wrap items-center gap-3 pt-2"
                 x-data="{
                     sending: false,
                     result: null,
                     async sendTest() {
                         this.sending = true;
                         this.result = null;
                         const form = this.$root.closest('form');
                         const data = new FormData(form);
                         data.delete('_method');
                         data.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
                         try {
                             const res = await fetch('{{ route('admin.notifications.test') }}', {
                                 method: 'POST',
                                 headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                 body: data,
                                 credentials: 'same-origin',
                             });
                             const json = await res.json().catch(() => ({}));
                             if (res.status === 422) {
                                 this.result = { ok: false, message: @js(__('Please complete the required fields for this channel type.')) };
                             } else {
                                 this.result = { ok: !!(res.ok && json.ok), message: json.message || @js(__('Test request failed.')) };
                             }
                         } catch (e) {
                             this.result = { ok: false, message: @js(__('Test request failed.')) };
                         } finally {
                             this.sending = false;
                         }
                     }
                 }">
                <x-ui.button type="submit" variant="primary">
                    {{ $channel ? __('Update') : __('Create') }}
                </x-ui.button>
                <x-ui.button type="button" x-on:click="sendTest()" x-bind:disabled="sending" variant="secondary">
                    <span x-show="! sending">{{ __('Send test') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Sending…') }}</span>
                </x-ui.button>
                <a href="{{ route('admin.notifications.index') }}" class="text-sm text-text-muted underline hover:text-text">{{ __('Cancel') }}</a>

                <p class="w-full text-sm" x-show="result" x-cloak
                   x-bind:class="result && result.ok ? 'text-success' : 'text-danger'"
                   x-text="result ? result.message : ''"
                   role="status" aria-live="polite"></p>
            </div>
        </form>
    </div>
</x-admin-layout>
