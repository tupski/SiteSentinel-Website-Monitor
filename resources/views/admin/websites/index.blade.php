<x-admin-layout>
    <x-slot name="title">{{ __('Monitored websites') }}</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Monitored websites') }}</h1>
        <a href="{{ route('admin.websites.create') }}" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            {{ __('Add website') }}
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @error('ids')
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $message }}
        </div>
    @enderror

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
            class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3"
        >
            <span class="text-sm font-medium text-slate-700">
                <span x-text="selected.length"></span> {{ __('selected') }}
            </span>

            <form method="POST" action="{{ route('admin.websites.bulk.enable') }}" class="inline-block">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                <button type="submit" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    {{ __('Enable') }}
                </button>
            </form>

            <form method="POST" action="{{ route('admin.websites.bulk.disable') }}" class="inline-block">
                @csrf
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
                <button type="submit" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    {{ __('Disable') }}
                </button>
            </form>

            <button type="button"
                    x-on:click="$dispatch('open-modal', { name: 'bulk-delete-websites' })"
                    class="rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                {{ __('Delete') }}
            </button>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="w-10 px-4 py-3 text-left">
                            <input type="checkbox"
                                   class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                   :checked="allSelected"
                                   x-effect="$el.indeterminate = someSelected"
                                   x-on:change="toggleAll($event.target.checked)"
                                   x-bind:disabled="allIds.length === 0"
                                   aria-label="{{ __('Select all websites') }}">
                        </th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('URL') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Availability') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Security') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Last check') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($websites as $website)
                        <tr>
                            <td class="px-4 py-3">
                                <input type="checkbox"
                                       value="{{ $website->id }}"
                                       x-model="selected"
                                       class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500"
                                       aria-label="{{ __('Select :name', ['name' => $website->name]) }}">
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $website->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $website->url }}</td>
                            <td class="px-4 py-3">{{ $website->status_availability ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $website->status_security ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $website->last_checked_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1">
                                    {{-- Run check: queues the job; no inline probe (AGENTS.md §9). --}}
                                    <form method="POST" action="{{ route('admin.websites.check', $website) }}" class="inline-block">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500"
                                                aria-label="{{ __('Run check for :name', ['name' => $website->name]) }}"
                                                title="{{ __('Run check') }}">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M5.25 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.99 20.06c-1.25.687-2.779-.217-2.779-1.643V5.653Z" />
                                            </svg>
                                        </button>
                                    </form>

                                    <a href="{{ route('admin.websites.edit', $website) }}"
                                       class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500"
                                       aria-label="{{ __('Edit :name', ['name' => $website->name]) }}"
                                       title="{{ __('Edit') }}">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                        </svg>
                                    </a>

                                    <form method="POST" action="{{ route('admin.websites.toggle', $website) }}" class="inline-block">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500"
                                                aria-label="{{ $website->is_active ? __('Disable :name', ['name' => $website->name]) : __('Enable :name', ['name' => $website->name]) }}"
                                                title="{{ $website->is_active ? __('Disable') : __('Enable') }}">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M5.636 5.636a9 9 0 1 0 12.728 0M12 3v9" />
                                            </svg>
                                        </button>
                                    </form>

                                    <button type="button"
                                            x-on:click="$dispatch('open-modal', { name: 'delete-website-{{ $website->id }}' })"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded text-red-600 hover:bg-red-50 hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500"
                                            aria-label="{{ __('Delete :name', ['name' => $website->name]) }}"
                                            title="{{ __('Delete') }}">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                </div>

                                {{-- Destructive action is always modal-gated (ADR-034). --}}
                                <x-modal-form
                                    :name="'delete-website-'.$website->id"
                                    :title="__('Delete website')"
                                    :action="route('admin.websites.destroy', $website)"
                                    method="DELETE"
                                >
                                    <p>{{ __('Delete “:name”? This cannot be undone.', ['name' => $website->name]) }}</p>
                                    <p class="mt-2 text-slate-500">
                                        {{ __('The website is removed from monitoring. Its incident history is append-only and is kept for the incident retention window; checks, snapshots, and notification logs are pruned by their own retention windows.') }}
                                    </p>
                                    <x-slot name="footer">
                                        <button type="button"
                                                x-on:click="$dispatch('close-modal', { name: 'delete-website-{{ $website->id }}' })"
                                                class="rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                                            {{ __('Cancel') }}
                                        </button>
                                        <button type="submit" class="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                            {{ __('Delete') }}
                                        </button>
                                    </x-slot>
                                </x-modal-form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">{{ __('No websites monitored yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

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
            <p class="mt-2 text-slate-500">
                {{ __('Removed websites stop being monitored. Incident history is append-only and is kept for the incident retention window; checks, snapshots, and notification logs are pruned by their own retention windows.') }}
            </p>
            <x-slot name="footer">
                <button type="button"
                        x-on:click="$dispatch('close-modal', { name: 'bulk-delete-websites' })"
                        class="rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    {{ __('Cancel') }}
                </button>
                <button type="submit" class="rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    {{ __('Delete selected') }}
                </button>
            </x-slot>
        </x-modal-form>
    </div>

    <div class="mt-4 flex items-center justify-between gap-4">
        <x-per-page />
        <div>{{ $websites->links() }}</div>
    </div>
</x-admin-layout>
