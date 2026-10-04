@php
    use Illuminate\Support\Facades\Storage;

    $logoPath = $values['site_logo'] ?? null;
    $faviconPath = $values['favicon'] ?? null;
    $logoUrl = is_string($logoPath) && $logoPath !== '' ? Storage::disk('public')->url($logoPath) : null;
    $faviconUrl = is_string($faviconPath) && $faviconPath !== '' ? Storage::disk('public')->url($faviconPath) : null;
@endphp
<x-admin-layout>
    <x-slot name="title">{{ __('Settings') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('System settings') }}</h1>
            <p class="text-sm text-text-muted">{{ __('Site identity, branding and display preferences.') }}</p>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            <x-ui.card :title="__('General')" :subtitle="__('The name and description shown across the app.')">
                <div class="space-y-4">
                    <x-form.field id="site_name" name="site_name" :label="__('Site name')" required
                                  hint="{{ __('Shown in the header and page titles.') }}"
                                  help="{{ __('The application name. Maximum 255 characters.') }}">
                        <x-ui.input id="site_name" name="site_name" type="text"
                               :value="old('site_name', $values['site_name'] ?? '')" required maxlength="255"
                               aria-describedby="site_name-hint site_name-error" :invalid="$errors->has('site_name')" />
                    </x-form.field>

                    <x-form.field id="site_description" name="site_description" :label="__('Site description')"
                                  hint="{{ __('Optional tagline.') }}"
                                  help="{{ __('A short tagline or description. Maximum 500 characters.') }}">
                        <x-ui.textarea id="site_description" name="site_description" rows="3"
                               aria-describedby="site_description-hint site_description-error"
                               :invalid="$errors->has('site_description')">{{ old('site_description', $values['site_description'] ?? '') }}</x-ui.textarea>
                    </x-form.field>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Branding')" :subtitle="__('Logo and favicon. Uploads replace the current file.')">
                <div class="space-y-6">
                    <x-form.field id="site_logo" name="site_logo" :label="__('Logo')"
                                  hint="{{ __('PNG, JPG, SVG or WebP.') }}"
                                  help="{{ __('The logo shown in the app. PNG, JPG, SVG or WebP, maximum 2 MB. Uploading replaces the current logo.') }}">
                        @if ($logoUrl)
                            <div class="mb-2 flex items-center gap-3">
                                <img src="{{ $logoUrl }}" alt="{{ __('Current site logo') }}"
                                     class="h-12 w-auto rounded border border-border bg-surface-elevated p-1" />
                                <x-ui.checkbox name="remove_site_logo" value="1"
                                               :label="__('Remove current logo')" />
                            </div>
                        @endif
                        <x-ui.input id="site_logo" name="site_logo" type="file"
                               accept=".png,.jpg,.jpeg,.svg,.webp,image/png,image/jpeg,image/svg+xml,image/webp"
                               aria-describedby="site_logo-hint site_logo-error" :invalid="$errors->has('site_logo')" />
                    </x-form.field>

                    <x-form.field id="favicon" name="favicon" :label="__('Favicon')"
                                  hint="{{ __('ICO, PNG or SVG.') }}"
                                  help="{{ __('The browser tab icon. ICO, PNG or SVG, maximum 256 KB. Uploading replaces the current favicon.') }}">
                        @if ($faviconUrl)
                            <div class="mb-2 flex items-center gap-3">
                                <img src="{{ $faviconUrl }}" alt="{{ __('Current favicon') }}"
                                     class="h-8 w-8 rounded border border-border bg-surface-elevated p-1" />
                                <x-ui.checkbox name="remove_favicon" value="1"
                                               :label="__('Remove current favicon')" />
                            </div>
                        @endif
                        <x-ui.input id="favicon" name="favicon" type="file"
                               accept=".ico,.png,.svg,image/vnd.microsoft.icon,image/x-icon,image/png,image/svg+xml"
                               aria-describedby="favicon-hint favicon-error" :invalid="$errors->has('favicon')" />
                    </x-form.field>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('System')" :subtitle="__('Deployment-wide display preferences.')">
                <x-form.field id="timezone" name="timezone" :label="__('Timezone')" required
                              hint="{{ __('Used for displayed timestamps.') }}"
                              help="{{ __('The timezone applied to dates shown in the app. Must be a valid PHP timezone identifier.') }}">
                    <x-ui.select id="timezone" name="timezone" required
                           aria-describedby="timezone-hint timezone-error" :invalid="$errors->has('timezone')">
                        @foreach ($timezones as $region => $identifiers)
                            <optgroup label="{{ $region }}">
                                @foreach ($identifiers as $identifier)
                                    <option value="{{ $identifier }}"
                                        @selected(old('timezone', $values['timezone'] ?? 'UTC') === $identifier)>
                                        {{ $identifier }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-ui.select>
                </x-form.field>
            </x-ui.card>

            <div class="flex justify-end">
                <x-ui.button type="submit" variant="primary">{{ __('Save settings') }}</x-ui.button>
            </div>
        </form>
    </div>
</x-admin-layout>
