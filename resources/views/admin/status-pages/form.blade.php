<x-admin-layout>
    <x-slot name="title">{{ $page ? __('Edit status page') : __('Add status page') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight">{{ $page ? __('Edit status page') : __('Add status page') }}</h1>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </div>
        @endif

        <form method="POST"
              action="{{ $page ? route('admin.status-pages.update', $page) : route('admin.status-pages.store') }}"
              class="mt-6 space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($page)
                @method('PUT')
            @endif

            <x-form.field name="name" :label="__('Name')" required
                          hint="{{ __('Internal name for this page.') }}"
                          help="{{ __('Shown as the heading of the public page. e.g. “Acme Status”. Maximum 191 characters.') }}">
                <input id="name" name="name" type="text" value="{{ old('name', $page?->name) }}" required
                       aria-describedby="name-hint" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </x-form.field>

            <x-form.field name="slug" :label="__('Slug')" required
                          hint="{{ __('URL segment: /status/{slug}.') }}"
                          help="{{ __('Unique across pages. Lowercase letters, numbers, dashes and underscores only. Must not collide with another page.') }}">
                <input id="slug" name="slug" type="text" value="{{ old('slug', $page?->slug) }}" required
                       aria-describedby="slug-hint" class="block w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </x-form.field>

            <x-form.field name="visibility_mode" :label="__('Visibility mode')" required
                          hint="{{ __('Who can see the page.') }}"
                          help="{{ __('Private is admin-only (404 to everyone else). Public is open to anyone with the URL. Password Protected requires a password to view.') }}">
                <div class="space-y-2">
                    @foreach ([\App\Models\StatusPage::MODE_PRIVATE, \App\Models\StatusPage::MODE_PUBLIC, \App\Models\StatusPage::MODE_PASSWORD_PROTECTED] as $mode)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="visibility_mode" value="{{ $mode }}"
                                   @checked(old('visibility_mode', $page?->visibility_mode ?? \App\Models\StatusPage::MODE_PRIVATE) === $mode)>
                            <span class="text-sm">{{ $mode }}</span>
                        </label>
                    @endforeach
                </div>
            </x-form.field>

            <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                {{ __('Going Public lists selected websites to anyone with the URL. Confirm below.') }}
            </div>

            <x-form.field name="confirm_public" :label="__('Confirm Public')"
                          hint="{{ __('Required to switch the page to Public.') }}"
                          help="{{ __('Public pages are readable by anyone who knows the URL. Tick this to acknowledge that the listed services become public.') }}">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="confirm_public" value="0">
                    <input type="checkbox" name="confirm_public" value="1" @checked((bool) old('confirm_public', false))>
                    <span class="text-sm">{{ __('I understand this exposes the page publicly') }}</span>
                </label>
            </x-form.field>

            <x-form.field name="is_default" :label="__('Default page')"
                          hint="{{ __('Target of the legacy /status redirect and fallback for unassigned websites.') }}"
                          help="{{ __('Only one page can be the default. Websites without an explicit page, and the legacy /status URL, resolve to it.') }}">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1" @checked((bool) old('is_default', $page?->is_default ?? false))>
                    <span class="text-sm">{{ __('Make this the default page') }}</span>
                </label>
            </x-form.field>

            @include('admin.status-settings._password-fields', ['settings' => $page])

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-slate-700">{{ __('Websites on this page') }}</legend>
                <p class="text-xs text-slate-500">{{ __('Selected websites appear on this page only. Websites assigned to another page are shown so you can move them.') }}</p>
                @forelse($websites as $website)
                    <div class="flex items-center justify-between gap-4 rounded border border-slate-200 p-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="published[]" value="{{ $website->id }}"
                                   @checked(in_array($website->id, old('published', $selectedIds), false))>
                            <span>{{ e($website->name) }}</span>
                            @if ($website->status_page_id !== null && $website->status_page_id !== $page?->id)
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ __('assigned elsewhere') }}</span>
                            @endif
                        </label>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('No websites yet.') }}</p>
                @endforelse
            </fieldset>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">{{ __('Save') }}</button>
                <a href="{{ route('admin.status-pages.index') }}" class="text-sm text-slate-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</x-admin-layout>
