@php
    use App\Support\HttpStatusCodes;
    use App\Services\Settings\SettingsRepository;

    $defaultInterval = (int) settings(SettingsRepository::MONITORING_DEFAULT_INTERVAL);
    $defaultTimeout = (int) settings(SettingsRepository::MONITORING_DEFAULT_TIMEOUT);
@endphp
<x-admin-layout>
    <x-slot name="title">{{ $website ? __('Edit website') : __('Add website') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ $website ? __('Edit website') : __('Add website') }}</h1>

        @if ($errors->any())
            <x-ui.alert variant="danger" class="mt-4">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ $website ? route('admin.websites.update', $website) : route('admin.websites.store') }}" class="mt-6 space-y-6 rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            @csrf
            @if ($website)
                @method('PUT')
            @endif

            <x-form.field name="name" :label="__('Name')" required
                          hint="{{ __('A short label shown in listings and alerts.') }}"
                          help="{{ __('Used only inside SiteSentinel. Choose something an operator will recognise, e.g. “Corporate site”. Maximum 255 characters.') }}">
                <x-ui.input id="name" name="name" type="text" :value="old('name', $website?->name)" required
                       aria-describedby="name-hint" :invalid="$errors->has('name')" />
            </x-form.field>

            <x-form.field name="url" :label="__('URL')" required
                          hint="{{ __('Full URL including https://.') }}"
                          help="{{ __('Must use http or https and resolve to a public destination. Private, loopback and link-local addresses are rejected (SSRF protection, SECURITY.md §5). Maximum 2048 characters.') }}">
                <x-ui.input id="url" name="url" type="url" :value="old('url', $website?->url)" required placeholder="https://example.com"
                       aria-describedby="url-hint" :invalid="$errors->has('url')" />
            </x-form.field>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <x-form.field name="check_interval_seconds" :label="__('Check interval (seconds)')" required
                              hint="{{ __('Minimum 60 seconds.') }}"
                              help="{{ __('How often the monitor probes this URL. 300s (5 minutes) is a reasonable default; shorter intervals increase load on the target. Minimum allowed is 60 seconds.') }}">
                    <x-ui.input id="check_interval_seconds" name="check_interval_seconds" type="number" min="60" :value="old('check_interval_seconds', $website?->check_interval_seconds ?? $defaultInterval)" required
                           aria-describedby="check_interval_seconds-hint" :invalid="$errors->has('check_interval_seconds')" />
                </x-form.field>

                <x-form.field name="timeout_seconds" :label="__('Timeout (seconds)')" required
                              hint="{{ __('Between 3 and 30 seconds.') }}"
                              help="{{ __('How long to wait for the response before treating the check as a failure. Must be between 3 and 30 seconds; keep it below the check interval.') }}">
                    <x-ui.input id="timeout_seconds" name="timeout_seconds" type="number" min="3" max="30" :value="old('timeout_seconds', $website?->timeout_seconds ?? $defaultTimeout)" required
                           aria-describedby="timeout_seconds-hint" :invalid="$errors->has('timeout_seconds')" />
                </x-form.field>

                <x-form.field name="expected_status" :label="__('Expected HTTP status')" required
                              hint="{{ __('Status code the site should return.') }}"
                              help="{{ __('The monitor raises RULE-AV-002 when the observed status differs from this value. Choose the code a healthy response returns — e.g. 200 for a normal page, 301/302 if the site is expected to redirect. 304 and 401 are always tolerated.') }}">
                    <x-ui.select id="expected_status" name="expected_status" required
                            aria-describedby="expected_status-hint" :invalid="$errors->has('expected_status')">
                        @foreach (HttpStatusCodes::CATALOGUE as $code => $label)
                            <option value="{{ $code }}" @selected((int) old('expected_status', $website?->expected_status ?? 200) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-form.field>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <x-form.field name="expected_title" :label="__('Expected page title')"
                              hint="{{ __('Optional.') }}"
                              help="{{ __('If set, the monitor compares the page <title> against this value and flags drift as a content-health signal. Leave blank to skip the check. Maximum 512 characters.') }}">
                    <x-ui.input id="expected_title" name="expected_title" type="text" :value="old('expected_title', $website?->expected_title)"
                           aria-describedby="expected_title-hint" :invalid="$errors->has('expected_title')" />
                </x-form.field>

                <x-form.field name="expected_final_domain" :label="__('Expected final domain')"
                              hint="{{ __('Optional. Use when redirects are expected.') }}"
                              help="{{ __('The domain the site should end up on after following redirects. If the final domain differs, the monitor raises a redirect-hijack signal. Leave blank to skip. Maximum 255 characters.') }}">
                    <x-ui.input id="expected_final_domain" name="expected_final_domain" type="text" :value="old('expected_final_domain', $website?->expected_final_domain)"
                           aria-describedby="expected_final_domain-hint" :invalid="$errors->has('expected_final_domain')" />
                </x-form.field>
            </div>

            <x-form.field name="note" :label="__('Note')"
                          hint="{{ __('Optional free text.') }}"
                          help="{{ __('Operator notes about this monitor — ownership, contacts, maintenance windows. Not sent in alert payloads.') }}">
                <x-ui.textarea id="note" name="note" rows="3"
                          aria-describedby="note-hint" :invalid="$errors->has('note')">{{ old('note', $website?->note) }}</x-ui.textarea>
            </x-form.field>

            <div class="flex flex-wrap items-center gap-4">
                <span class="inline-flex items-center gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <x-ui.checkbox name="is_active" value="1" :checked="(bool) old('is_active', $website?->is_active ?? true)" />
                    <span class="text-sm text-text-muted">{{ __('Active') }}</span>
                </span>

                @php
                    $toggles = ['follow_redirects', 'monitor_ssl', 'monitor_redirects', 'monitor_content', 'monitor_security'];
                @endphp
                @foreach ($toggles as $toggle)
                    <span class="inline-flex items-center gap-2">
                        <input type="hidden" name="{{ $toggle }}" value="0">
                        <x-ui.checkbox name="{{ $toggle }}" value="1" :checked="(bool) old($toggle, $website?->$toggle ?? true)" />
                        <span class="text-sm text-text-muted">{{ __(str_replace('_', ' ', ucfirst($toggle))) }}</span>
                    </span>
                @endforeach
            </div>

            <fieldset class="rounded border border-border p-4">
                <legend class="px-2 text-sm font-medium text-text">{{ __('Notification channels') }}</legend>
                <p class="text-xs text-text-subtle">{{ __('Leave all unchecked to use all enabled channels.') }}</p>
                @php $checkedIds = array_map('strval', (array) old('channel_ids', $selectedChannels ?? [])); @endphp
                <div class="mt-2 flex flex-wrap gap-4">
                    @forelse (($channels ?? collect()) as $ch)
                        <span class="inline-flex items-center gap-2">
                            <x-ui.checkbox name="channel_ids[]" :value="$ch->id" :checked="in_array((string) $ch->id, $checkedIds, true)" />
                            <span class="text-sm text-text-muted">{{ $ch->name }} <span class="text-text-subtle">({{ $ch->type }}{{ $ch->enabled ? '' : ', disabled' }})</span></span>
                        </span>
                    @empty
                        <p class="text-sm text-text-muted">{{ __('No channels configured yet.') }}</p>
                    @endforelse
                </div>
                @error('channel_ids')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </fieldset>

            <div class="flex items-center gap-3 pt-2">
                <x-ui.button type="submit" variant="primary">
                    {{ $website ? __('Update') : __('Create') }}
                </x-ui.button>
                <x-ui.button :href="route('admin.websites.index')" variant="secondary">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </div>
</x-admin-layout>
