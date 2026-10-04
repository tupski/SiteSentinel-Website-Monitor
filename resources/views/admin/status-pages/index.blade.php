<x-admin-layout>
    <x-slot name="title">{{ __('Status pages') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Status pages') }}</h1>
        <x-ui.button :href="route('admin.status-pages.create')" variant="primary">
            {{ __('Add status page') }}
        </x-ui.button>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4">{{ session('status') }}</x-ui.alert>
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
                    <td class="px-4 py-3"><code>{{ e($page->slug) }}</code></td>
                    <td class="px-4 py-3">{{ e($page->visibility_mode) }}</td>
                    <td class="px-4 py-3">{{ (int) $page->websites_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button :href="route('admin.status-pages.edit', $page)" variant="secondary" size="sm"
                               :aria-label="__('Edit :name', ['name' => $page->name])">{{ __('Edit') }}</x-ui.button>

                        @unless ($page->is_default)
                            <x-ui.button type="button"
                                    x-data
                                    x-on:click="$dispatch('open-modal', { name: 'delete-status-page-{{ $page->id }}' })"
                                    variant="danger" size="sm" class="ml-2"
                                    :aria-label="__('Delete :name', ['name' => $page->name])">{{ __('Delete') }}</x-ui.button>

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
