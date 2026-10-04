<x-admin-layout>
    <x-slot name="title">{{ __('Status pages') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Status pages') }}</h1>
        <x-ui.button :href="route('admin.status-pages.create')" variant="primary">
            {{ __('Add status page') }}
        </x-ui.button>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4" :dismissible="true">{{ session('status') }}</x-ui.alert>
    @endif

    @error('status_page')
        <x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mb-3 flex items-center justify-end">
        <x-per-page />
    </div>

    <x-ui.table>
        <x-ui.table-head>
            <tr>
                <th scope="col" class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">{{ __('Slug') }}</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">{{ __('Visibility') }}</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">{{ __('Websites') }}</th>
                <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Actions') }}</th>
            </tr>
        </x-ui.table-head>
        <x-ui.table-body>
            @forelse ($pages as $page)
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-medium text-text">{{ e($page->name) }}</span>
                        @if ($page->is_default)
                            <x-ui.badge variant="neutral" class="ml-2">{{ __('Default') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {{-- Requirement 24: the slug badge carries the LEADING SLASH and links to
                             the real public status route — but only when the page is publicly
                             reachable. Private / Password Protected pages render a non-link badge
                             so the admin is never sent to a dead/404 destination (same rule as the
                             Websites page). Route resolved via `route('status.show', $page)`
                             (StatusPage::getRouteKeyName() is `slug`), never a hand-built string. --}}
                        @if ($page->isPublic())
                            <a href="{{ route('status.show', $page) }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               title="{{ __('Open :name status page', ['name' => $page->name]) }}"
                               class="inline-flex rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">
                                <x-ui.badge variant="info">/{{ $page->slug }}</x-ui.badge>
                            </a>
                        @else
                            <x-ui.badge variant="neutral"
                                        :title="$page->isPasswordProtected() ? __('Password protected') : __('Private')">/{{ $page->slug }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ e($page->visibility_mode) }}</td>
                    <td class="px-4 py-3">{{ (int) $page->websites_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            {{-- Requirement 24: icon-only row actions with accessible labels
                                 (ADR-034). Analytics (Requirement 23) opens the internal report. --}}
                            <x-ui.button :href="route('admin.status-pages.analytics', $page)" variant="ghost" icon-only size="sm"
                                   :aria-label="__('View analytics')" :title="__('View analytics')">
                                <x-ui.icon name="chart-bar" />
                            </x-ui.button>

                            <x-ui.button :href="route('admin.status-pages.edit', $page)" variant="ghost" icon-only size="sm"
                                   :aria-label="__('Edit status page')" :title="__('Edit status page')">
                                <x-ui.icon name="pencil" />
                            </x-ui.button>

                            @unless ($page->is_default)
                                <x-ui.button type="button"
                                        x-data
                                        x-on:click="$dispatch('open-modal', { name: 'delete-status-page-{{ $page->id }}' })"
                                        variant="ghost" icon-only size="sm"
                                        class="!text-danger hover:!bg-danger-muted hover:!text-danger"
                                        :aria-label="__('Delete :name', ['name' => $page->name])"
                                        :title="__('Delete')">
                                    <x-ui.icon name="x-mark" />
                                </x-ui.button>
                            @endunless
                        </div>

                        @unless ($page->is_default)
                            <x-modal name="delete-status-page-{{ $page->id }}" :title="__('Delete status page')">
                                <p>{{ __('Delete “:name”? Websites assigned to it will fall back to the default page. This cannot be undone.', ['name' => $page->name]) }}</p>
                                <x-slot name="footer">
                                    <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-modal', { name: 'delete-status-page-{{ $page->id }}' })">
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                    <form method="POST" action="{{ route('admin.status-pages.destroy', $page) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger">{{ __('Delete') }}</x-ui.button>
                                    </form>
                                </x-slot>
                            </x-modal>
                        @endunless
                    </td>
                </tr>
            @empty
                <x-ui.table-empty :columns="5" :title="__('No status pages yet.')" />
            @endforelse
        </x-ui.table-body>
    </x-ui.table>

    <div class="mt-4">
        {{ $pages->links() }}
    </div>
</x-admin-layout>
