<x-admin-layout>
    <x-slot name="title">{{ __('Delivery log') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Delivery log') }}</h1>
        <x-ui.button :href="route('admin.notifications.index')" variant="secondary" size="sm">
            {{ __('Back to channels') }}
        </x-ui.button>
    </div>

    <form method="GET" action="{{ route('admin.notification-logs.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-border bg-surface-elevated p-4 shadow-sm">
        <div>
            <label for="filter-status" class="block text-xs font-medium text-text-muted">{{ __('Status') }}</label>
            <x-ui.select id="filter-status" name="status" class="mt-1">
                <option value="">{{ __('All') }}</option>
                @foreach (['queued', 'sent', 'failed', 'suppressed'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $s }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div>
            <label for="filter-channel" class="block text-xs font-medium text-text-muted">{{ __('Channel') }}</label>
            <x-ui.select id="filter-channel" name="channel_id" class="mt-1">
                <option value="">{{ __('All') }}</option>
                @foreach ($channels as $c)
                    <option value="{{ $c->id }}" @selected((string) ($filters['channel_id'] ?? '') === (string) $c->id)>{{ $c->name }} ({{ $c->type }})</option>
                @endforeach
            </x-ui.select>
        </div>
        <div>
            <label for="filter-incident" class="block text-xs font-medium text-text-muted">{{ __('Incident ID') }}</label>
            <x-ui.input id="filter-incident" name="incident_id" type="number" min="1" :value="$filters['incident_id'] ?? ''" placeholder="{{ __('Any') }}" class="mt-1 w-32" />
        </div>
        <x-ui.button type="submit" variant="primary">{{ __('Filter') }}</x-ui.button>
        <a href="{{ route('admin.notification-logs.index') }}" class="text-sm text-text-muted underline hover:text-text">{{ __('Reset') }}</a>
    </form>

    <x-ui.table>
        <x-ui.table-head>
            <tr>
                <th class="px-4 py-3 text-left font-medium">{{ __('ID') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Channel') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Incident') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Status') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Attempt') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Provider ID') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Error') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('At') }}</th>
            </tr>
        </x-ui.table-head>
        <x-ui.table-body>
            @forelse ($logs as $log)
                <tr>
                    <td class="px-4 py-3">{{ $log->id }}</td>
                    <td class="px-4 py-3">{{ $log->channel?->name ?? '—' }} <span class="text-text-subtle">({{ $log->channel?->type ?? '—' }})</span></td>
                    <td class="px-4 py-3">
                        @if ($log->incident_id)
                            <a href="{{ route('admin.incidents.show', $log->incident_id) }}" class="underline hover:text-text">#{{ $log->incident_id }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $statusVariant = match ($log->status) {
                                'sent' => 'success',
                                'failed' => 'danger',
                                'suppressed' => 'warning',
                                default => 'neutral',
                            };
                        @endphp
                        <x-ui.badge :variant="$statusVariant">{{ $log->status }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3">{{ $log->attempt }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $log->provider_message_id ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-xs truncate text-text-muted">{{ $log->error ?? '—' }}</td>
                    <td class="px-4 py-3 text-text-subtle">{{ ($log->sent_at ?? $log->created_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
                </tr>
            @empty
                <x-ui.table-empty :columns="8" :title="__('No delivery records yet.')" />
            @endforelse
        </x-ui.table-body>
    </x-ui.table>

    <div class="mt-4 flex items-center justify-between gap-4">
        <x-per-page />
        <div>{{ $logs->links() }}</div>
    </div>
</x-admin-layout>
