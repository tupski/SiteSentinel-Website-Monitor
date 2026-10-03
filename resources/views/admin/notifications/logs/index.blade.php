<x-admin-layout>
    <x-slot name="title">{{ __('Delivery log') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Delivery log') }}</h1>
        <a href="{{ route('admin.notifications.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Back to channels') }}</a>
    </div>

    <form method="GET" action="{{ route('admin.notification-logs.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <div>
            <label for="filter-status" class="block text-xs font-medium text-slate-600">{{ __('Status') }}</label>
            <select id="filter-status" name="status" class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ __('All') }}</option>
                @foreach (['queued', 'sent', 'failed', 'suppressed'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-channel" class="block text-xs font-medium text-slate-600">{{ __('Channel') }}</label>
            <select id="filter-channel" name="channel_id" class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ __('All') }}</option>
                @foreach ($channels as $c)
                    <option value="{{ $c->id }}" @selected((string) ($filters['channel_id'] ?? '') === (string) $c->id)>{{ $c->name }} ({{ $c->type }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-incident" class="block text-xs font-medium text-slate-600">{{ __('Incident ID') }}</label>
            <input id="filter-incident" name="incident_id" type="number" min="1" value="{{ $filters['incident_id'] ?? '' }}" placeholder="{{ __('Any') }}" class="mt-1 w-32 rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">{{ __('Filter') }}</button>
        <a href="{{ route('admin.notification-logs.index') }}" class="text-sm text-slate-600 underline hover:text-slate-900">{{ __('Reset') }}</a>
    </form>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('ID') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Channel') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Incident') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Attempt') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Provider ID') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Error') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('At') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->id }}</td>
                        <td class="px-4 py-3">{{ $log->channel?->name ?? '—' }} <span class="text-slate-400">({{ $log->channel?->type ?? '—' }})</span></td>
                        <td class="px-4 py-3">
                            @if ($log->incident_id)
                                <a href="{{ route('admin.incidents.show', $log->incident_id) }}" class="underline hover:text-slate-900">#{{ $log->incident_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $log->status }}</td>
                        <td class="px-4 py-3">{{ $log->attempt }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $log->provider_message_id ?? '—' }}</td>
                        <td class="px-4 py-3 max-w-xs truncate text-slate-600">{{ $log->error ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ ($log->sent_at ?? $log->created_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-slate-500">{{ __('No delivery records yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</x-admin-layout>
