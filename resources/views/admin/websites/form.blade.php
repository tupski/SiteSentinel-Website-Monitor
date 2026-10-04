@php use App\Support\HttpStatusCodes; @endphp
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

            <x-form.field name="name" :label="__('Name')" required
                          hint="{{ __('A short label shown in listings and alerts.') }}"
                          help="{{ __('Used only inside SiteSentinel. Choose something an operator will recognise, e.g. “Corporate site”. Maximum 255 characters.') }}">
                <input id="name" name="name" type="text" value="{{ old('name', $website?->name) }}" required
                       aria-describedby="name-hint" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                       class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            </x-form.field>

            <x-form.field name="url" :label="__('URL')" required
                          hint="{{ __('Full URL including https://.') }}"
                          help="{{ __('Must use http or https and resolve to a public destination. Private, loopback and link-local addresses are rejected (SSRF protection, SECURITY.md §5). Maximum 2048 characters.') }}">
                <input id="url" name="url" type="url" value="{{ old('url', $website?->url) }}" required placeholder="https://example.com"
                       aria-describedby="url-hint" aria-invalid="{{ $errors->has('url') ? 'true' : 'false' }}"
                       class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            </x-form.field>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <x-form.field name="check_interval_seconds" :label="__('Check interval (seconds)')" required
                              hint="{{ __('Minimum 60 seconds.') }}"
                              help="{{ __('How often the monitor probes this URL. 300s (5 minutes) is a reasonable default; shorter intervals increase load on the target. Minimum allowed is 60 seconds.') }}">
                    <input id="check_interval_seconds" name="check_interval_seconds" type="number" min="60" value="{{ old('check_interval_seconds', $website?->check_interval_seconds ?? 300) }}" required
                           aria-describedby="check_interval_seconds-hint" aria-invalid="{{ $errors->has('check_interval_seconds') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>

                <x-form.field name="timeout_seconds" :label="__('Timeout (seconds)')" required
                              hint="{{ __('Between 3 and 30 seconds.') }}"
                              help="{{ __('How long to wait for the response before treating the check as a failure. Must be between 3 and 30 seconds; keep it below the check interval.') }}">
                    <input id="timeout_seconds" name="timeout_seconds" type="number" min="3" max="30" value="{{ old('timeout_seconds', $website?->timeout_seconds ?? 10) }}" required
                           aria-describedby="timeout_seconds-hint" aria-invalid="{{ $errors->has('timeout_seconds') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>

                <x-form.field name="expected_status" :label="__('Expected HTTP status')" required
                              hint="{{ __('Status code the site should return.') }}"
                              help="{{ __('The monitor raises RULE-AV-002 when the observed status differs from this value. Choose the code a healthy response returns — e.g. 200 for a normal page, 301/302 if the site is expected to redirect. 304 and 401 are always tolerated.') }}">
                    <select id="expected_status" name="expected_status" required
                            aria-describedby="expected_status-hint" aria-invalid="{{ $errors->has('expected_status') ? 'true' : 'false' }}"
                            class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                        @foreach (HttpStatusCodes::CATALOGUE as $code => $label)
                            <option value="{{ $code }}" @selected((int) old('expected_status', $website?->expected_status ?? 200) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-form.field>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <x-form.field name="expected_title" :label="__('Expected page title')"
                              hint="{{ __('Optional.') }}"
                              help="{{ __('If set, the monitor compares the page <title> against this value and flags drift as a content-health signal. Leave blank to skip the check. Maximum 512 characters.') }}">
                    <input id="expected_title" name="expected_title" type="text" value="{{ old('expected_title', $website?->expected_title) }}"
                           aria-describedby="expected_title-hint" aria-invalid="{{ $errors->has('expected_title') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>

                <x-form.field name="expected_final_domain" :label="__('Expected final domain')"
                              hint="{{ __('Optional. Use when redirects are expected.') }}"
                              help="{{ __('The domain the site should end up on after following redirects. If the final domain differs, the monitor raises a redirect-hijack signal. Leave blank to skip. Maximum 255 characters.') }}">
                    <input id="expected_final_domain" name="expected_final_domain" type="text" value="{{ old('expected_final_domain', $website?->expected_final_domain) }}"
                           aria-describedby="expected_final_domain-hint" aria-invalid="{{ $errors->has('expected_final_domain') ? 'true' : 'false' }}"
                           class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </x-form.field>
            </div>

            <x-form.field name="note" :label="__('Note')"
                          hint="{{ __('Optional free text.') }}"
                          help="{{ __('Operator notes about this monitor — ownership, contacts, maintenance windows. Not sent in alert payloads.') }}">
                <textarea id="note" name="note" rows="3"
                          aria-describedby="note-hint" aria-invalid="{{ $errors->has('note') ? 'true' : 'false' }}"
                          class="block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">{{ old('note', $website?->note) }}</textarea>
            </x-form.field>

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
