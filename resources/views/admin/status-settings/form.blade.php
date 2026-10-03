<x-admin-layout>
    <x-slot name="title">Status page settings — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-bold tracking-tight">Status page settings</h1>

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

            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Visibility mode</legend>
                <div class="mt-2 space-y-2">
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
                @error('visibility_mode')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>

            <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Going Public lists selected websites to anyone with URL. Confirm below.
            </div>

            <label class="flex items-center gap-2">
                <input type="hidden" name="confirm_public" value="0">
                <input type="checkbox" name="confirm_public" value="1" @checked((bool) old('confirm_public'))>
                <span class="text-sm">I confirm making status page public</span>
            </label>
            @error('confirm_public')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    @error('password_confirmation')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <label class="flex items-center gap-2">
                <input type="hidden" name="clear_password" value="0">
                <input type="checkbox" name="clear_password" value="1">
                <span class="text-sm">Clear existing password</span>
            </label>

            <div>
                <label for="slug" class="block text-sm font-medium text-slate-700">Slug (storage only)</label>
                <input id="slug" name="slug" type="text" value="{{ old('slug', $settings->slug) }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('slug')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label for="branding_title" class="block text-sm font-medium text-slate-700">Brand title</label>
                    <input id="branding_title" name="branding[title]" type="text" value="{{ old('branding.title', $settings->branding['title'] ?? '') }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="branding_message" class="block text-sm font-medium text-slate-700">Custom message</label>
                    <textarea id="branding_message" name="branding[message]" rows="3" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('branding.message', $settings->branding['message'] ?? '') }}</textarea>
                </div>
                <div>
                    <label for="branding_footer" class="block text-sm font-medium text-slate-700">Footer</label>
                    <input id="branding_footer" name="branding[footer]" type="text" value="{{ old('branding.footer', $settings->branding['footer'] ?? '') }}" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <fieldset class="rounded border border-slate-200 p-4">
                <legend class="px-2 text-sm font-medium text-slate-700">Published websites + alias</legend>
                <div class="space-y-3">
                    @forelse($websites as $website)
                        <div class="flex flex-col gap-2 md:flex-row md:items-center">
                            <label class="flex items-center gap-2 md:w-1/2">
                                <input type="checkbox" name="published[]" value="{{ $website->id }}" @checked(in_array($website->id, old('published', $websites->where('is_visible_on_status', true)->pluck('id')->all()), false))>
                                <span class="text-sm">{{ e($website->name) }}</span>
                            </label>
                            <input name="aliases[{{ $website->id }}]" type="text" value="{{ old('aliases.'.$website->id, $website->status_alias) }}" placeholder="Alias (optional)" class="w-full rounded border border-slate-300 px-3 py-2 text-sm md:w-1/2">
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No websites yet.</p>
                    @endforelse
                </div>
            </fieldset>

            <label class="flex items-center gap-2">
                <input type="hidden" name="history_enabled" value="0">
                <input type="checkbox" name="history_enabled" value="1" @checked((bool) old('history_enabled', false))>
                <span class="text-sm">Enable day-level history (default off)</span>
            </label>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Save</button>
                <a href="{{ route('admin.dashboard') }}" class="text-sm text-slate-600 underline">Cancel</a>
            </div>
        </form>
    </div>
</x-admin-layout>
