<x-admin-layout>
    <x-slot name="title">{{ $channel ? __('Edit channel') : __('Add channel') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight">{{ $channel ? __('Edit channel') : __('Add channel') }}</h1>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
                @error('config')<p class="mt-1">{{ $message }}</p>@enderror
            </div>
        @endif

        <form method="POST" action="{{ $channel ? route('admin.notifications.update', $channel) : route('admin.notifications.store') }}" class="mt-6 space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm" data-turbo="true">
            @csrf
            @if ($channel)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="type" class="block text-sm font-medium text-slate-700">{{ __('Type') }}</label>
                    <select id="type" name="type" required class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="email" @selected(old('type', $channel?->type ?? 'email') === 'email')>email</option>
                        <option value="telegram" @selected(old('type', $channel?->type) === 'telegram')>telegram</option>
                    </select>
                    @error('type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">{{ __('Name') }}</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $channel?->name) }}" required maxlength="255"
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="inline-flex items-center gap-2">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $channel?->enabled ?? true)) class="rounded border-slate-300">
                    <span class="text-sm text-slate-700">{{ __('Enabled') }}</span>
                </label>
                <div>
                    <label for="min_severity" class="block text-sm font-medium text-slate-700">{{ __('Minimum severity') }}</label>
                    <select id="min_severity" name="min_severity" class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="WARNING" @selected(old('min_severity', ($config['min_severity'] ?? 'WARNING')) === 'WARNING')>WARNING</option>
                        <option value="CRITICAL" @selected(old('min_severity', ($config['min_severity'] ?? '')) === 'CRITICAL')>CRITICAL</option>
                    </select>
                </div>
            </div>

            <fieldset class="rounded border border-slate-200 p-4">
                <legend class="px-2 text-sm font-medium text-slate-700">{{ __('Email settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="email_recipients" class="block text-sm font-medium text-slate-700">{{ __('Recipients (one per line)') }}</label>
                        <textarea id="email_recipients" name="email_recipients_text" rows="2" placeholder="ops@example.com"
                                  class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('email_recipients_text', isset($config['recipients']) ? implode("\n", (array) $config['recipients']) : '') }}</textarea>
                        @php $recipientsOld = old('email_recipients'); @endphp
                        @if (is_array($recipientsOld))
                            @foreach ($recipientsOld as $r)
                                <input type="hidden" name="email_recipients[]" value="{{ $r }}">
                            @endforeach
                        @elseif (isset($config['recipients']) && is_array($config['recipients']))
                            @foreach ((array) $config['recipients'] as $r)
                                <input type="hidden" name="email_recipients[]" value="{{ $r }}">
                            @endforeach
                        @endif
                        @error('email_recipients')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('email_recipients.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email_host" class="block text-sm font-medium text-slate-700">{{ __('SMTP host') }}</label>
                        <input id="email_host" name="email_host" type="text" value="{{ old('email_host', $config['host'] ?? '') }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="email_port" class="block text-sm font-medium text-slate-700">{{ __('SMTP port') }}</label>
                        <input id="email_port" name="email_port" type="number" min="1" max="65535" value="{{ old('email_port', $config['port'] ?? 587) }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="email_username" class="block text-sm font-medium text-slate-700">{{ __('SMTP username') }}</label>
                        <input id="email_username" name="email_username" type="text" value="{{ old('email_username', $config['username'] ?? '') }}" autocomplete="off" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="email_encryption" class="block text-sm font-medium text-slate-700">{{ __('Encryption') }}</label>
                        <select id="email_encryption" name="email_encryption" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <option value="tls" @selected(old('email_encryption', ($config['encryption'] ?? 'tls')) === 'tls')>tls</option>
                            <option value="ssl" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'ssl')>ssl</option>
                            <option value="none" @selected(old('email_encryption', ($config['encryption'] ?? '')) === 'none')>none</option>
                        </select>
                    </div>
                    <div>
                        <label for="email_from_address" class="block text-sm font-medium text-slate-700">{{ __('From address') }}</label>
                        <input id="email_from_address" name="email_from_address" type="email" value="{{ old('email_from_address', $config['from_address'] ?? '') }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="email_from_name" class="block text-sm font-medium text-slate-700">{{ __('From name') }}</label>
                        <input id="email_from_name" name="email_from_name" type="text" value="{{ old('email_from_name', $config['from_name'] ?? 'SiteSentinel') }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            </fieldset>

            <fieldset class="rounded border border-slate-200 p-4">
                <legend class="px-2 text-sm font-medium text-slate-700">{{ __('Telegram settings') }}</legend>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label for="telegram_chat_id" class="block text-sm font-medium text-slate-700">{{ __('Chat ID') }}</label>
                        <input id="telegram_chat_id" name="telegram_chat_id" type="text" value="{{ old('telegram_chat_id', $config['chat_id'] ?? '') }}" autocomplete="off" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        @error('telegram_chat_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="telegram_thread_id" class="block text-sm font-medium text-slate-700">{{ __('Topic thread ID (optional)') }}</label>
                        <input id="telegram_thread_id" name="telegram_thread_id" type="text" value="{{ old('telegram_thread_id', $config['message_thread_id'] ?? '') }}" autocomplete="off" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            </fieldset>

            <div>
                <label for="secret_ref" class="block text-sm font-medium text-slate-700">{{ __('Secret (SMTP password or bot token)') }}</label>
                <input id="secret_ref" name="secret_ref" type="password" value="" autocomplete="new-password" placeholder="{{ $hasSecret ? __('Leave empty to keep existing secret') : __('Required') }}"
                       class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @if ($hasSecret)
                    <p class="mt-1 text-xs text-slate-500">{{ __('A secret is stored. Leave empty to keep it.') }}</p>
                @endif
                @error('secret_ref')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ $channel ? __('Update') : __('Create') }}
                </button>
                <a href="{{ route('admin.notifications.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Cancel') }}</a>
            </div>
        </form>

        <script>
            (function () {
                var ta = document.getElementById('email_recipients');
                if (!ta) return;
                function syncHidden() {
                    document.querySelectorAll('input[name="email_recipients[]"]').forEach(function (el) { el.remove(); });
                    ta.value.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (addr) {
                        var h = document.createElement('input');
                        h.type = 'hidden'; h.name = 'email_recipients[]'; h.value = addr;
                        ta.closest('form').appendChild(h);
                    });
                }
                ta.closest('form').addEventListener('submit', syncHidden);
                syncHidden();
            })();
        </script>
    </div>
</x-admin-layout>
