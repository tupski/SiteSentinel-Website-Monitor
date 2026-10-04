<x-admin-layout>
    <x-slot name="title">Status page settings — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight">Status page settings</h1>
        <p class="mt-2 text-sm text-slate-600">
            {{ __('Applying to the default status page:') }}
            <strong>{{ $settings->name }}</strong>
            (<code>{{ $settings->slug }}</code>).
            <a href="{{ route('admin.status-pages.index') }}" class="underline">{{ __('Manage status pages') }}</a>
        </p>

        @if(session('status'))
            <div class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ e(session('status')) }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">Please correct the errors below.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.status-settings.update') }}" class="mt-6 space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <x-form.field name="visibility_mode" label="Visibility mode" required
                          hint="{{ __('Who can see the status page.') }}"
                          help="{{ __('Private keeps the page admin-only. Public exposes it to anyone with the URL (requires confirmation below). Password Protected gates access behind the password you set.') }}">
                <div class="space-y-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="visibility_mode" value="Private" @checked(old('visibility_mode', $settings->visibility_mode) === 'Private')>
                        <span class="text-sm">Private (admin only, hidden)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="visibility_mode" value="Public" @checked(old('visibility_mode', $settings->visibility_mode) === 'Public')>
                        <span class="text-sm">Public (anyone with URL)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="visibility_mode" value="Password Protected" @checked(old('visibility_mode', $settings->visibility_mode) === 'Password Protected')>
                        <span class="text-sm">Password Protected</span>
                    </label>
                </div>
            </x-form.field>

            <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Going Public lists selected websites to anyone with URL. Confirm below.
            </div>

            <x-form.field name="confirm_public" :label="__('Confirm Public')"
                          hint="{{ __('Required to switch the page to Public.') }}"
                          help="{{ __('Public pages are readable by anyone who knows the URL. Tick this to acknowledge that the listed services become public.') }}">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="confirm_public" value="0">
                    <input type="checkbox" name="confirm_public" value="1" @checked((bool) old('confirm_public', false))>
                    <span class="text-sm">I understand this exposes the page publicly</span>
                </label>
            </x-form.field>

            <x-form.field name="slug" :label="__('Slug')"
                          hint="{{ __('URL segment for this page.') }}"
                          help="{{ __('Lowercase letters, numbers, dashes and underscores. The public page is served at /status/{slug}.') }}">
                <input id="slug" name="slug" type="text" value="{{ old('slug', $settings->slug) }}"
                       aria-describedby="slug-hint" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </x-form.field>

            @include('admin.status-settings._password-fields', ['settings' => $settings])

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-slate-700">{{ __('Published websites (on the default page)') }}</legend>
                @forelse($websites as $website)
                    <div class="flex items-center justify-between gap-4 rounded border border-slate-200 p-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="published[]" value="{{ $website->id }}"
                                   @checked(in_array($website->id, old('published', $publishedIds), false))>
                            <span>{{ e($website->name) }}</span>
                        </label>
                        <input type="text" name="aliases[{{ $website->id }}]" value="{{ old('aliases.'.$website->id, $website->status_alias) }}"
                               placeholder="{{ __('Alias (optional)') }}"
                               class="block w-56 rounded border border-slate-300 px-2 py-1 text-sm">
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('No websites yet.') }}</p>
                @endforelse
            </fieldset>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save</button>
                <a href="{{ route('admin.dashboard') }}" class="text-sm text-slate-600 underline">Cancel</a>
            </div>
        </form>
    </div>
</x-admin-layout>
