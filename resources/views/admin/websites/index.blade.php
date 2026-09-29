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

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
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
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $website->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $website->url }}</td>
                        <td class="px-4 py-3">{{ $website->status_availability ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $website->status_security ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $website->last_checked_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.websites.edit', $website) }}" class="mr-3 text-slate-600 underline hover:text-slate-900">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('admin.websites.toggle', $website) }}" class="inline-block mr-3">
                                @csrf
                                <button type="submit" class="text-slate-600 underline hover:text-slate-900">
                                    {{ $website->is_active ? __('Disable') : __('Enable') }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.websites.destroy', $website) }}" class="inline-block" onsubmit="return confirm('{{ __('Delete this website?') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 underline hover:text-red-800">{{ __('Delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('No websites monitored yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
