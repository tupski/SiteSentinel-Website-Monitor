<x-admin-layout>
    <x-slot name="title">Status page settings — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight text-text">Status page settings</h1>
        <p class="mt-2 text-sm text-text-muted">
            {{ __('Applying to the default status page:') }}
            <strong class="text-text">{{ $settings->name }}</strong>
            (<code>{{ $settings->slug }}</code>).
            <a href="{{ route('admin.status-pages.index') }}" class="underline hover:text-text">{{ __('Manage status pages') }}</a>
        </p>

        @if(session('status'))
            <x-ui.alert variant="success" class="mt-4" :dismissible="true">{{ e(session('status')) }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" class="mt-4">
                <p class="font-medium">Please correct the errors below.</p>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('admin.status-settings.update') }}" class="mt-6 space-y-6 rounded-lg border border-border bg-surface-elevated p-6 shadow-sm">
            @csrf
            @method('PUT')

            <x-form.field name="visibility_mode" label="Visibility mode" required
                          hint="{{ __('Who can see the status page.') }}"
                          help="{{ __('Private keeps the page admin-only. Public exposes it to anyone with the URL (requires confirmation below). Password Protected gates access behind the password you set.') }}">
                <div class="space-y-2">
                    <x-ui.radio name="visibility_mode" value="Private" label="Private (admin only, hidden)"
                           :checked="old('visibility_mode', $settings->visibility_mode) === 'Private'" />
                    <x-ui.radio name="visibility_mode" value="Public" label="Public (anyone with URL)"
                           :checked="old('visibility_mode', $settings->visibility_mode) === 'Public'" />
                    <x-ui.radio name="visibility_mode" value="Password Protected" label="Password Protected"
                           :checked="old('visibility_mode', $settings->visibility_mode) === 'Password Protected'" />
                </div>
            </x-form.field>

            <x-ui.alert variant="warning">
                Going Public lists selected websites to anyone with URL. Confirm below.
            </x-ui.alert>

            <x-form.field name="confirm_public" :label="__('Confirm Public')"
                          hint="{{ __('Required to switch the page to Public.') }}"
                          help="{{ __('Public pages are readable by anyone who knows the URL. Tick this to acknowledge that the listed services become public.') }}">
                <input type="hidden" name="confirm_public" value="0">
                <x-ui.checkbox name="confirm_public" value="1" :checked="(bool) old('confirm_public', false)"
                        label="I understand this exposes the page publicly" />
            </x-form.field>

            <x-form.field name="slug" :label="__('Slug')"
                          hint="{{ __('URL segment for this page.') }}"
                          help="{{ __('Lowercase letters, numbers, dashes and underscores. The public page is served at /status/{slug}.') }}">
                <x-ui.input id="slug" name="slug" type="text" :value="old('slug', $settings->slug)"
                       aria-describedby="slug-hint" :invalid="$errors->has('slug')" />
            </x-form.field>

            @include('admin.status-settings._password-fields', ['settings' => $settings])

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-text">{{ __('Published websites (on the default page)') }}</legend>
                @forelse($websites as $website)
                    <div class="flex items-center justify-between gap-4 rounded border border-border p-3">
                        <x-ui.checkbox name="published[]" value="{{ $website->id }}"
                               :checked="in_array($website->id, old('published', $publishedIds), false)"
                               :label="e($website->name)" />
                        <x-ui.input type="text" name="aliases[{{ $website->id }}]" :value="old('aliases.'.$website->id, $website->status_alias)"
                               placeholder="{{ __('Alias (optional)') }}"
                               class="w-56" />
                    </div>
                @empty
                    <p class="text-sm text-text-muted">{{ __('No websites yet.') }}</p>
                @endforelse
            </fieldset>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <a href="{{ route('admin.dashboard') }}" class="text-sm text-text-muted underline hover:text-text">Cancel</a>
            </div>
        </form>
    </div>
</x-admin-layout>
