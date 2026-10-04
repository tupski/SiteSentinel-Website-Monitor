<x-admin-layout>
    <x-slot name="title">{{ __('Status pages') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Status pages') }}</h1>
        <a href="{{ route('admin.status-pages.create') }}" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            {{ __('Add status page') }}
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @error('status_page')
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="mb-3 flex items-center justify-end">
        <x-per-page />
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-slate-600">{{ __('Name') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-slate-600">{{ __('Slug') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-slate-600">{{ __('Visibility') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-medium text-slate-600">{{ __('Websites') }}</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium text-slate-600">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pages as $page)
                    <tr>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ e($page->name) }}</span>
                            @if ($page->is_default)
                                <span class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ __('Default') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3"><code>{{ e($page->slug) }}</code></td>
                        <td class="px-4 py-3">{{ e($page->visibility_mode) }}</td>
                        <td class="px-4 py-3">{{ (int) $page->websites_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.status-pages.edit', $page) }}"
                               class="rounded border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100"
                               aria-label="{{ __('Edit :name', ['name' => $page->name]) }}">{{ __('Edit') }}</a>

                            @unless ($page->is_default)
                                <button type="button"
                                        x-data
                                        x-on:click="$dispatch('open-modal', { name: 'delete-status-page-{{ $page->id }}' })"
                                        class="ml-2 rounded bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700"
                                        aria-label="{{ __('Delete :name', ['name' => $page->name]) }}">{{ __('Delete') }}</button>

                                <x-modal name="delete-status-page-{{ $page->id }}" :title="__('Delete status page')">
                                    <p>{{ __('Delete “:name”? Websites assigned to it will fall back to the default page. This cannot be undone.', ['name' => $page->name]) }}</p>
                                    <x-slot name="footer">
                                        <button type="button" x-on:click="$dispatch('close-modal', { name: 'delete-status-page-{{ $page->id }}' })"
                                                class="rounded border border-slate-300 px-3 py-1.5 text-sm">{{ __('Cancel') }}</button>
                                        <form method="POST" action="{{ route('admin.status-pages.destroy', $page) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">{{ __('Delete') }}</button>
                                        </form>
                                    </x-slot>
                                </x-modal>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('No status pages yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $pages->links() }}
    </div>
</x-admin-layout>
