<x-admin-layout>
    <x-slot name="title">{{ $website ? __('Edit website') : __('Add website') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight">{{ $website ? __('Edit website') : __('Add website') }}</h1>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </div>
        @endif

        <form method="POST" action="{{ $website ? route('admin.websites.update', $website) : route('admin.websites.store') }}" class="mt-6 space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($website)
                @method('PUT')
            @endif

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">{{ __('Name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name', $website?->name) }}" required
                       class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="url" class="block text-sm font-medium text-slate-700">{{ __('URL') }}</label>
                <input id="url" name="url" type="url" value="{{ old('url', $website?->url) }}" required placeholder="https://example.com"
                       class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                <p class="mt-1 text-xs text-slate-500">{{ __('Must use http or https and point to a public, non-internal destination.') }}</p>
                @error('url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div>
                    <label for="check_interval_seconds" class="block text-sm font-medium text-slate-700">{{ __('Check interval (seconds)') }}</label>
                    <input id="check_interval_seconds" name="check_interval_seconds" type="number" min="60" value="{{ old('check_interval_seconds', $website?->check_interval_seconds ?? 300) }}" required
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('check_interval_seconds')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="timeout_seconds" class="block text-sm font-medium text-slate-700">{{ __('Timeout (seconds)') }}</label>
                    <input id="timeout_seconds" name="timeout_seconds" type="number" min="3" max="30" value="{{ old('timeout_seconds', $website?->timeout_seconds ?? 10) }}" required
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('timeout_seconds')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="expected_status" class="block text-sm font-medium text-slate-700">{{ __('Expected HTTP status') }}</label>
                    <input id="expected_status" name="expected_status" type="number" min="100" max="599" value="{{ old('expected_status', $website?->expected_status ?? 200) }}" required
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('expected_status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="expected_title" class="block text-sm font-medium text-slate-700">{{ __('Expected page title') }}</label>
                    <input id="expected_title" name="expected_title" type="text" value="{{ old('expected_title', $website?->expected_title) }}"
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('expected_title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="expected_final_domain" class="block text-sm font-medium text-slate-700">{{ __('Expected final domain') }}</label>
                    <input id="expected_final_domain" name="expected_final_domain" type="text" value="{{ old('expected_final_domain', $website?->expected_final_domain) }}"
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('expected_final_domain')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="note" class="block text-sm font-medium text-slate-700">{{ __('Note') }}</label>
                <textarea id="note" name="note" rows="3"
                          class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">{{ old('note', $website?->note) }}</textarea>
                @error('note')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="inline-flex items-center gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $website?->is_active ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                    <span class="text-sm text-slate-700">{{ __('Active') }}</span>
                </label>

                @php
                    $toggles = ['follow_redirects', 'monitor_ssl', 'monitor_redirects', 'monitor_content', 'monitor_security'];
                @endphp
                @foreach ($toggles as $toggle)
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="{{ $toggle }}" value="0">
                        <input type="checkbox" name="{{ $toggle }}" value="1" @checked(old($toggle, $website?->$toggle ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                        <span class="text-sm text-slate-700">{{ __(str_replace('_', ' ', ucfirst($toggle))) }}</span>
                    </label>
                @endforeach
            </div>

            <fieldset class="rounded border border-slate-200 p-4">
                <legend class="px-2 text-sm font-medium text-slate-700">{{ __('Notification channels') }}</legend>
                <p class="text-xs text-slate-500">{{ __('Leave all unchecked to use all enabled channels.') }}</p>
                @php $checkedIds = array_map('strval', (array) old('channel_ids', $selectedChannels ?? [])); @endphp
                <div class="mt-2 flex flex-wrap gap-4">
                    @forelse (($channels ?? collect()) as $ch)
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="channel_ids[]" value="{{ $ch->id }}" @checked(in_array((string) $ch->id, $checkedIds, true)) class="rounded border-slate-300">
                            <span class="text-sm text-slate-700">{{ $ch->name }} <span class="text-slate-400">({{ $ch->type }}{{ $ch->enabled ? '' : ', disabled' }})</span></span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('No channels configured yet.') }}</p>
                    @endforelse
                </div>
                @error('channel_ids')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ $website ? __('Update') : __('Create') }}
                </button>
                <a href="{{ route('admin.websites.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-admin-layout>
