<x-admin-layout>
    <x-slot name="title">{{ $channel ? __('Edit channel') : __('Add channel') }} — {{ __('Notification') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <nav class="mb-4 text-sm" aria-label="{{ __('Notification sections') }}">
            <a href="{{ route('admin.notifications.index') }}" class="text-slate-600 underline hover:text-slate-900">{{ __('Notification') }}</a>
            <span class="mx-1 text-slate-400" aria-hidden="true">/</span>
            <span class="text-slate-900">{{ $channel ? __('Edit channel') : __('Add channel') }}</span>
        </nav>

        <h1 class="text-2xl font-bold tracking-tight">{{ $channel ? __('Edit channel') : __('Add channel') }}</h1>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
                @error('config')<p class="mt-1">{{ $message }}</p>@enderror
            </div>
        @endif

        <form method="POST"
              action="{{ $channel ? route('admin.notifications.update', $channel) : route('admin.notifications.store') }}"
              class="mt-6 space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
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
                    <select id="type" name="type" required x-model="type"
                            aria-describedby="type-hint" aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}"
                            class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                        <option value="email">{{ __('Email (SMTP)') }}</option>
                        <option value="telegram">{{ __('Telegram (Bot API)') }}</option>
                    </select>
                </x-form.field>

                <x-form.field name="name" :label="__('Name')" required
                              hint="{{ __('A short label shown in listings and alerts.') }}"
                              help="{{ __('Used only inside SiteSentinel. Choose something an operator will recognise, e.g. “Ops email”. Maximum 255 characters.') }}">
                    <input id="name" name="name" type="text" value="{{ old('name', $channel?->name) }}" required maxlength="255"
                           aria-describedby="name-hint" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="inline-flex items-center gap-2">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $channel?->enabled ?? true)) class="rounded border-slate-300">
                    <span class="text-sm text-slate-700">{{ __('Enabled') }}</span>
                </label>

                <x-form.field name="min_severity" :label="__('Minimum severity')"
                              hint="{{ __('WARNING or CRITICAL.') }}"
                              help="{{ __('Only events at or above this severity are delivered. CRITICAL delivers escalations but suppresses routine WARNING incidents.') }}">
                    <select id="min_severity" name="min_severity"
                            aria-describedby="min_severity-hint"
                            class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                        <option value="WARNING" @selected(old('min_severity', ($config['min_severity'] ?? 'WARNING')) === 'WARNING')>WARNING</option>
                        <option value="CRITICAL" @selected(old('min_severity', ($config['min_severity'] ?? '')) === 'CRITICAL')>CRITICAL</option>
                    </select>
                </x-form.field>
            </div>

            {{-- Email-only settings. Hidden + disabled when Telegram is selected so
                 no irrelevant field is ever submitted (Plan S3). --}}
            <fieldset class="rounded border border-slate-200 p-4" x-show="type === 'email'" x-cloak>
                <legend class="px-2 text-sm font-medium text-slate-700">{{ __('Email settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <x-form.field name="email_recipients" id="email_recipients" :label="__('Recipients (one per line)')" required
                                      hint="{{ __('Up to 20 addresses.') }}"
                                      help="{{ __('One recipient per line (or comma-separated). These addresses receive the alert emails. Maximum 20 recipients; each must be a valid email address.') }}">
                            <textarea id="email_recipients" name="email_recipients_text" rows="2" placeholder="ops@example.com"
                                      x-bind:disabled="type !== 'email'"
                                      aria-describedby="email_recipients-hint" aria-invalid="{{ $errors->has('email_recipients') ? 'true' : 'false' }}"
                                      class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">{{ old('email_recipients_text', isset($config['recipients']) ? implode("\n", (array) $config['recipients']) : '') }}</textarea>
                        </x-form.field>
                        @error('email_recipients.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <x-form.field name="email_host" :label="__('SMTP host')" required
                                  hint="{{ __('e.g. smtp.example.com') }}"
                                  help="{{ __('The SMTP server hostname used to send alert email. Required for email channels.') }}">
                        <input id="email_host" name="email_host" type="text" value="{{ old('email_host', $config['host'] ?? '') }}"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_host-hint" aria-invalid="{{ $errors->has('email_host') ? 'true' : 'false' }}"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>

                    <x-form.field name="email_port" :label="__('SMTP port')" required
                                  hint="{{ __('Usually 587 (tls) or 465 (ssl).') }}"
                                  help="{{ __('The TCP port of the SMTP server. Must be between 1 and 65535. Port 587 with tls is the common default.') }}">
                        <input id="email_port" name="email_port" type="number" min="1" max="65535" value="{{ old('email_port', $config['port'] ?? 587) }}"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_port-hint" aria-invalid="{{ $errors->has('email_port') ? 'true' : 'false' }}"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>

                    <x-form.field name="email_username" :label="__('SMTP username')"
                                  hint="{{ __('Optional.') }}"
                                  help="{{ __('Username for SMTP authentication, if the server requires it. The password is supplied in the secret field below.') }}">
                        <input id="email_username" name="email_username" type="text" value="{{ old('email_username', $config['username'] ?? '') }}" autocomplete="off"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_username-hint"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>

                    <x-form.field name="email_encryption" :label="__('Encryption')"
                                  hint="{{ __('tls, ssl or none.') }}"
                                  help="{{ __('Transport encryption for the SMTP connection. Production rejects “none”; use tls (STARTTLS) or ssl (implicit TLS).') }}">
                        <select id="email_encryption" name="email_encryption" x-bind:disabled="type !== 'email'"
                                aria-describedby="email_encryption-hint"
                                class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                            <option value="tls" @selected(old('email_encryption', ($config['encryption'] ?? 'tls')) === 'tls')>tls</option>
                            <option value="ssl" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'ssl')>ssl</option>
                            <option value="none" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'none')>none</option>
                        </select>
                    </x-form.field>

                    <x-form.field name="email_from_address" :label="__('From address')" required
                                  hint="{{ __('Sender address shown to recipients.') }}"
                                  help="{{ __('The envelope/From address alert emails are sent from. Must be a valid email address; many SMTP servers require it to be a domain you control.') }}">
                        <input id="email_from_address" name="email_from_address" type="email" value="{{ old('email_from_address', $config['from_address'] ?? '') }}"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_from_address-hint" aria-invalid="{{ $errors->has('email_from_address') ? 'true' : 'false' }}"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>

                    <x-form.field name="email_from_name" :label="__('From name')"
                                  hint="{{ __('Optional display name.') }}"
                                  help="{{ __('Friendly sender name shown alongside the From address, e.g. “SiteSentinel”.') }}">
                        <input id="email_from_name" name="email_from_name" type="text" value="{{ old('email_from_name', $config['from_name'] ?? 'SiteSentinel') }}"
                               x-bind:disabled="type !== 'email'"
                               aria-describedby="email_from_name-hint"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>
                </div>
            </fieldset>

            {{-- Telegram-only settings. Hidden + disabled when Email is selected. --}}
            <fieldset class="rounded border border-slate-200 p-4" x-show="type === 'telegram'" x-cloak>
                <legend class="px-2 text-sm font-medium text-slate-700">{{ __('Telegram settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-form.field name="telegram_chat_id" :label="__('Chat ID')" required
                                  hint="{{ __('Numeric chat or channel id.') }}"
                                  help="{{ __('The Telegram chat/channel the bot posts to. Required for Telegram channels. The bot must be a member of that chat.') }}">
                        <input id="telegram_chat_id" name="telegram_chat_id" type="text" value="{{ old('telegram_chat_id', $config['chat_id'] ?? '') }}" autocomplete="off"
                               x-bind:disabled="type !== 'telegram'"
                               aria-describedby="telegram_chat_id-hint" aria-invalid="{{ $errors->has('telegram_chat_id') ? 'true' : 'false' }}"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>

                    <x-form.field name="telegram_thread_id" :label="__('Topic thread ID (optional)')"
                                  hint="{{ __('For forum topics only.') }}"
                                  help="{{ __('Optional. Set only when posting into a specific topic of a Telegram forum group; leave blank otherwise.') }}">
                        <input id="telegram_thread_id" name="telegram_thread_id" type="text" value="{{ old('telegram_thread_id', $config['message_thread_id'] ?? '') }}" autocomplete="off"
                               x-bind:disabled="type !== 'telegram'"
                               aria-describedby="telegram_thread_id-hint"
                               class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    </x-form.field>
                </div>
            </fieldset>

            <x-form.field name="secret_ref" :label="__('Secret (SMTP password or bot token)')"
                          :hint="$hasSecret ? __('A secret is stored. Leave empty to keep it.') : __('Required to deliver alerts.')"
                          help="{{ __('The SMTP password (email) or Telegram bot token. Stored encrypted at rest, never shown again, and never logged. On an existing channel, leave blank to keep the current secret.') }}">
                <input id="secret_ref" name="secret_ref" type="password" value="" autocomplete="new-password"
                       placeholder="{{ $hasSecret ? __('Leave empty to keep existing secret') : __('Required') }}"
                       aria-describedby="secret_ref-hint" aria-invalid="{{ $errors->has('secret_ref') ? 'true' : 'false' }}"
                       class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
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
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ $channel ? __('Update') : __('Create') }}
                </button>
                <button type="button" x-on:click="sendTest()" x-bind:disabled="sending"
                        class="rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50">
                    <span x-show="! sending">{{ __('Send test') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Sending…') }}</span>
                </button>
                <a href="{{ route('admin.notifications.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Cancel') }}</a>

                <p class="w-full text-sm" x-show="result" x-cloak
                   x-bind:class="result && result.ok ? 'text-green-700' : 'text-red-700'"
                   x-text="result ? result.message : ''"
                   role="status" aria-live="polite"></p>
            </div>
        </form>
    </div>
</x-admin-layout>
