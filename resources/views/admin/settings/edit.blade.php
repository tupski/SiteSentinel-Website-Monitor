@php
    use App\Services\Settings\SettingsRepository;
    use Illuminate\Support\Facades\Storage;

    $logoPath = $values['site_logo'] ?? null;
    $faviconPath = $values['favicon'] ?? null;
    $logoUrl = is_string($logoPath) && $logoPath !== '' ? Storage::disk('public')->url($logoPath) : null;
    $faviconUrl = is_string($faviconPath) && $faviconPath !== '' ? Storage::disk('public')->url($faviconPath) : null;

    // Group order + registry entries come from the repository so the page and
    // the key registry can never drift apart (ADR-043).
    $numericGroups = ['monitoring', 'detection', 'notifications', 'retention', 'security'];
@endphp
<x-admin-layout>
    <x-slot name="title">{{ __('Settings') }} — {{ $siteName ?? config('app.name', 'SiteSentinel') }}</x-slot>

    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('System settings') }}</h1>
            <p class="text-sm text-text-muted">{{ __('Site identity, branding, operational tuning and version control.') }}</p>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]">
            {{-- ------------------------------ Settings form ------------------------------ --}}
            <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                {{-- General --}}
                <x-ui.card :title="__('General')" :subtitle="__('The name and description shown across the app.')">
                    <div class="space-y-4">
                        <x-form.field id="site_name" name="site_name" :label="__('Site name')" required
                                      hint="{{ __('Shown in the header, page titles and alert subjects.') }}"
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

                {{-- Branding --}}
                <x-ui.card :title="__('Branding')" :subtitle="__('Logo and favicon. Uploads replace the current file.')">
                    <div class="space-y-6">
                        <x-form.field id="site_logo" name="site_logo" :label="__('Logo')"
                                      hint="{{ __('PNG, JPG, SVG or WebP.') }}"
                                      help="{{ __('The logo shown in the app header and login page. PNG, JPG, SVG or WebP, maximum 2 MB. Uploading replaces the current logo.') }}">
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

                {{-- System --}}
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

                {{-- Operational tuning groups (monitoring, detection, notifications, retention, security) --}}
                @foreach ($numericGroups as $group)
                    @php($entries = $definitions[$group] ?? [])
                    @continue(empty($entries))

                    <x-ui.card :title="__($groups[$group]['label'] ?? $group)" :subtitle="__($groups[$group]['subtitle'] ?? '')">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ($entries as $key => $definition)
                                @php($field = $definition['field'])
                                <x-form.field :id="$field" :name="$field" :label="__($definition['label'])"
                                              :hint="$definition['help']"
                                              :help="$definition['help']">
                                    <x-ui.input :id="$field" :name="$field" type="number"
                                           :value="old($field, $values[$key] ?? '')"
                                           aria-describedby="{{ $field }}-hint {{ $field }}-error"
                                           :invalid="$errors->has($field)" />
                                </x-form.field>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach

                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="primary">{{ __('Save settings') }}</x-ui.button>
                </div>
            </form>

            {{-- ------------------------------ Version control ------------------------------ --}}
            <aside class="space-y-6 lg:sticky lg:top-20 lg:self-start">
                <x-ui.card :title="__('Version control')" :subtitle="__('Pull the latest state or roll back to a snapshot.')">
                    <div class="space-y-4">
                        <p class="text-sm text-text-muted">
                            {{ __('Every save records an immutable snapshot. Pulling reconciles to the configured upstream (or the latest snapshot); rolling back restores a chosen version. The current state is always snapshotted first.') }}
                        </p>

                        <form method="POST" action="{{ route('admin.settings.pull') }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" class="w-full">
                                {{ __('Pull update') }}
                            </x-ui.button>
                        </form>
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('History')" :subtitle="__('Most recent :count versions.', ['count' => count($versions)])">
                    @if ($versions->isEmpty())
                        <p class="text-sm text-text-muted">{{ __('No versions yet. Save the settings to create the first snapshot.') }}</p>
                    @else
                        <ul class="space-y-3">
                            @foreach ($versions as $version)
                                <li class="rounded border border-border p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-text">v{{ $version->version }}</span>
                                        <x-ui.badge :variant="$version->source === 'rollback' ? 'warning' : ($version->source === 'pull' ? 'info' : 'neutral')">
                                            {{ __($version->sourceLabel()) }}
                                        </x-ui.badge>
                                    </div>
                                    <p class="mt-1 text-xs text-text-subtle">
                                        {{ $version->created_at?->diffForHumans() ?? '' }}
                                        @if ($version->author)
                                            · {{ $version->author->name }}
                                        @endif
                                    </p>
                                    @if ($version->label)
                                        <p class="mt-1 text-xs text-text-muted">{{ $version->label }}</p>
                                    @endif

                                    <form method="POST" action="{{ route('admin.settings.rollback', $version) }}" class="mt-2"
                                          onsubmit="return confirm('{{ __('Roll back to version :version? The current settings will be snapshotted first.', ['version' => $version->version]) }}');">
                                        @csrf
                                        <x-ui.button type="submit" variant="outline" size="sm" class="w-full">
                                            {{ __('Roll back to v:version', ['version' => $version->version]) }}
                                        </x-ui.button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </aside>
        </div>
    </div>
</x-admin-layout>
