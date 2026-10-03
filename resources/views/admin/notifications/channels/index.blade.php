<x-admin-layout>
    <x-slot name="title">{{ __('Notification channels') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Notification channels') }}</h1>
        <a href="{{ route('admin.notifications.create') }}" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            {{ __('Add channel') }}
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
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Enabled') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Failed sends') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($channels as $channel)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $channel->name }}</td>
                        <td class="px-4 py-3">{{ $channel->type }}</td>
                        <td class="px-4 py-3">{{ $channel->enabled ? __('Yes') : __('No') }}</td>
                        <td class="px-4 py-3">{{ $channel->failed_count ?? 0 }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.notifications.edit', $channel) }}" class="mr-3 text-slate-600 underline hover:text-slate-900">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('admin.notifications.test-send', $channel) }}" class="mr-3 inline-block">
                                @csrf
                                <button type="submit" class="text-slate-600 underline hover:text-slate-900">{{ __('Send test') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.notifications.destroy', $channel) }}" class="inline-block" onsubmit="return confirm('{{ __('Delete this channel?') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 underline hover:text-red-800">{{ __('Delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('No channels configured yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        <a href="{{ route('admin.notification-logs.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('View delivery log') }}</a>
    </div>
</x-admin-layout>
