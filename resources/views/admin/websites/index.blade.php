<x-admin-layout>
    <x-slot name="title">{{ __('Monitored websites') }}</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Monitored websites') }}</h1>
        <x-ui.button :href="route('admin.websites.create')" variant="primary">
            {{ __('Add website') }}
        </x-ui.button>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4" :dismissible="true">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    @error('ids')
        <x-ui.alert variant="danger" class="mb-4">
            {{ $message }}
        </x-ui.alert>
    @enderror

    {{-- Requirement 31: reflect the active availability filter and offer a
         one-click way to remove it. The filter is a whitelisted query parameter
         (`status`), so the filtered list is bookmarkable/refreshable and the
         count matches the dashboard "Operational" card. --}}
    @php($statusLabels = [
        'UP' => __('Operational'),
        'DOWN' => __('Down'),
        'unknown' => __('Unknown'),
    ])
    @if (($statusFilter ?? null) !== null)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface-muted px-4 py-3">
            <span class="text-sm text-text-muted">{{ __('Showing:') }}</span>
            <x-ui.badge :variant="$statusFilter === 'UP' ? 'success' : ($statusFilter === 'DOWN' ? 'danger' : 'neutral')">
                {{ $statusLabels[$statusFilter] ?? $statusFilter }}
            </x-ui.badge>
            <a href="{{ route('admin.websites.index') }}"
               class="text-sm text-text-muted underline hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                {{ __('Clear filter') }}
            </a>
        </div>
    @endif

    {{-- Bulk selection + action bar (ADR-034). Alpine owns only this local UI
         state; every action still posts through a CSRF-protected form. --}}
    <div
        x-data="{
            selected: [],
            allIds: @js($websites->pluck('id')->values()),
            toggleAll(checked) {
                this.selected = checked ? [...this.allIds] : [];
            },
            get allSelected() {
                return this.allIds.length > 0 && this.selected.length === this.allIds.length;
            },
            get someSelected() {
                return this.selected.length > 0 && ! this.allSelected;
            },
        }"
    >
        <div
            x-show="selected.length > 0"
            x-cloak
            class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface-muted px-4 py-3"
        >
            <span class="text-sm font-medium text-text">
                <span x-text="selected.length"></span> {{ __('selected') }}
            </span>

            <form method="POST" action="{{ route('admin.websites.bulk.enable') }}" class="inline-block">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                <x-ui.button type="submit" variant="secondary" size="sm">
                    {{ __('Enable') }}
                </x-ui.button>
            </form>

            <form method="POST" action="{{ route('admin.websites.bulk.disable') }}" class="inline-block">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                <x-ui.button type="submit" variant="secondary" size="sm">
                    {{ __('Disable') }}
                </x-ui.button>
            </form>

            <x-ui.button type="button"
                    x-on:click="$dispatch('open-modal', { name: 'bulk-delete-websites' })"
                    variant="danger" size="sm">
                {{ __('Delete') }}
            </x-ui.button>
        </div>

        <x-ui.table>
            <x-ui.table-head>
                <tr>
                    <th scope="col" class="w-10 px-4 py-3 text-left">
                        <label class="inline-flex">
                            <x-ui.checkbox
                                :aria-label="__('Select all websites')"
                                x-bind:checked="allSelected"
                                x-effect="$el.indeterminate = someSelected"
                                x-on:change="toggleAll($event.target.checked)"
                                x-bind:disabled="allIds.length === 0"
                            />
                        </label>
                    </th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('URL') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Status page') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Availability') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Security') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Last check') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Actions') }}</th>
                </tr>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($websites as $website)
                    <tr>
                        <td class="px-4 py-3">
                            <x-ui.checkbox
                                :value="$website->id"
                                x-model="selected"
                                :aria-label="__('Select :name', ['name' => $website->name])"
                            />
                        </td>
                        <td class="px-4 py-3 font-medium text-text">{{ $website->name }}</td>
                        @php($safeUrl = $website->safeUrl())
                        <td class="px-4 py-3 text-text-muted">
                            @if ($safeUrl !== null)
                                {{-- B2: real anchor; scheme checked via Website::safeUrl() so a
                                     stored value can never inject a non-http(s) href. Long URLs
                                     truncate inside the cell (no page-level overflow) and the
                                     full URL is still available via `title`. --}}
                                <a href="{{ $safeUrl }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   title="{{ $safeUrl }}"
                                   class="block max-w-[16rem] truncate text-info underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">
                                    {{ $safeUrl }}
                                </a>
                            @else
                                <span class="block max-w-[16rem] truncate" title="{{ $website->url }}">{{ $website->url }}</span>
                            @endif
                        </td>
                        @php($page = $website->statusPage)
                        <td class="px-4 py-3">
                            @if ($page === null)
                                <x-ui.badge variant="neutral">{{ __('Not linked') }}</x-ui.badge>
                            @elseif ($page->isPublic())
                                {{-- B3: only a publicly-reachable page is linkable; Private /
                                     Password Protected pages render as a non-link badge so the
                                     admin never gets a dead/404 destination. Route is resolved
                                     from the real status page route (status.show), never built
                                     from a raw value. --}}
                                <a href="{{ route('status.show', $page) }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   title="{{ __('Open :name status page', ['name' => $page->name]) }}"
                                   class="inline-flex rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">
                                    <x-ui.badge variant="info">{{ $page->slug }}</x-ui.badge>
                                </a>
                            @else
                                <x-ui.badge variant="neutral"
                                            :title="$page->isPasswordProtected() ? __('Password protected') : __('Private')">
                                    {{ $page->slug }}
                                </x-ui.badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $website->status_availability ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $website->status_security ?? '—' }}</td>
                        <td class="px-4 py-3 text-text-muted">{{ $website->last_checked_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                {{-- Run check: queues the job; no inline probe (AGENTS.md §9). --}}
                                <form method="POST" action="{{ route('admin.websites.check', $website) }}" class="inline-block">
                                    @csrf
                                    <x-ui.button type="submit" variant="ghost" icon-only size="sm"
                                            :aria-label="__('Run check for :name', ['name' => $website->name])"
                                            :title="__('Run check')">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M5.25 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.99 20.06c-1.25.687-2.779-.217-2.779-1.643V5.653Z" />
                                        </svg>
                                    </x-ui.button>
                                </form>

                                <x-ui.button :href="route('admin.websites.edit', $website)" variant="ghost" icon-only size="sm"
                                       :aria-label="__('Edit :name', ['name' => $website->name])"
                                       :title="__('Edit')">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </x-ui.button>

                                <form method="POST" action="{{ route('admin.websites.toggle', $website) }}" class="inline-block">
                                    @csrf
                                    {{-- B1: the toggle shows the ACTION to take, not the current
                                         state. Enabled rows offer "Disable monitoring" (pause-circle,
                                         danger tint); disabled rows offer "Enable monitoring"
                                         (play-circle, success tint). Distinct glyphs + semantic
                                         tokens, so the difference is obvious in both themes. --}}
                                    <x-ui.button type="submit" variant="ghost" icon-only size="sm"
                                            :class="$website->is_active ? '!text-danger hover:!bg-danger-muted hover:!text-danger' : '!text-success hover:!bg-success-muted hover:!text-success'"
                                            :aria-label="$website->is_active ? __('Disable monitoring for :name', ['name' => $website->name]) : __('Enable monitoring for :name', ['name' => $website->name])"
                                            :title="$website->is_active ? __('Disable monitoring') : __('Enable monitoring')">
                                        <x-ui.icon :name="$website->is_active ? 'pause-circle' : 'play-circle'" />
                                    </x-ui.button>
                                </form>

                                <x-ui.button type="button"
                                        x-on:click="$dispatch('open-modal', { name: 'delete-website-{{ $website->id }}' })"
                                        variant="ghost" icon-only size="sm"
                                        class="!text-danger hover:!bg-danger-muted hover:!text-danger"
                                        :aria-label="__('Delete :name', ['name' => $website->name])"
                                        :title="__('Delete')">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </x-ui.button>
                            </div>

                            {{-- Destructive action is always modal-gated (ADR-034). --}}
                            <x-modal-form
                                :name="'delete-website-'.$website->id"
                                :title="__('Delete website')"
                                :action="route('admin.websites.destroy', $website)"
                                method="DELETE"
                            >
                                <p>{{ __('Delete “:name”? This cannot be undone.', ['name' => $website->name]) }}</p>
                                <p class="mt-2 text-text-muted">
                                    {{ __('The website is removed from monitoring. Its incident history is append-only and is kept for the incident retention window; checks, snapshots, and notification logs are pruned by their own retention windows.') }}
                                </p>
                                <x-slot name="footer">
                                    <x-ui.button type="button"
                                            x-on:click="$dispatch('close-modal', { name: 'delete-website-{{ $website->id }}' })"
                                            variant="outline">
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                    <x-ui.button type="submit" variant="danger">
                                        {{ __('Delete') }}
                                    </x-ui.button>
                                </x-slot>
                            </x-modal-form>
                        </td>
                    </tr>
                @empty
                    <x-ui.table-empty :columns="8" :title="__('No websites monitored yet.')" />
                @endforelse
            </x-ui.table-body>
        </x-ui.table>

        {{-- Bulk delete confirmation; ids are injected from the live selection. --}}
        <x-modal-form
            name="bulk-delete-websites"
            :title="__('Delete selected websites')"
            :action="route('admin.websites.bulk.delete')"
            method="POST"
        >
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <p>{{ __('Delete the selected websites? This cannot be undone.') }}</p>
            <p class="mt-2 text-text-muted">
                {{ __('Removed websites stop being monitored. Incident history is append-only and is kept for the incident retention window; checks, snapshots, and notification logs are pruned by their own retention windows.') }}
            </p>
            <x-slot name="footer">
                <x-ui.button type="button"
                        x-on:click="$dispatch('close-modal', { name: 'bulk-delete-websites' })"
                        variant="outline">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="danger">
                    {{ __('Delete selected') }}
                </x-ui.button>
            </x-slot>
        </x-modal-form>
    </div>

    <div class="mt-4 flex items-center justify-between gap-4">
        <x-per-page />
        <div>{{ $websites->links() }}</div>
    </div>
</x-admin-layout>
