<x-admin-layout>
    <x-slot name="title">{{ __('Incidents') }} — SiteSentinel Admin</x-slot>

    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Incidents') }}</h1>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    {{-- FR-62: filterable by state, severity, type, and website. --}}
    <form method="GET" action="{{ route('admin.incidents.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-text-muted">{{ __('Status') }}</span>
            <x-ui.select name="status" class="mt-1 !w-auto">
                <option value="">{{ __('All') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </x-ui.select>
        </label>
        <label class="text-sm">
            <span class="block text-text-muted">{{ __('Severity') }}</span>
            <x-ui.select name="severity" class="mt-1 !w-auto">
                <option value="">{{ __('All') }}</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity }}" @selected(request('severity') === $severity)>{{ $severity }}</option>
                @endforeach
            </x-ui.select>
        </label>
        <label class="text-sm">
            <span class="block text-text-muted">{{ __('Type') }}</span>
            <x-ui.select name="type" class="mt-1 !w-auto">
                <option value="">{{ __('All') }}</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>
                @endforeach
            </x-ui.select>
        </label>
        <x-ui.button type="submit" variant="primary" size="sm">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.table>
        <x-ui.table-head>
            <tr>
                <th class="px-4 py-3 text-left font-medium">{{ __('Website') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Type') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Severity') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Status') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Detected') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Actions') }}</th>
            </tr>
        </x-ui.table-head>
        <x-ui.table-body>
            @forelse ($incidents as $incident)
                <tr>
                    <td class="px-4 py-3 font-medium text-text">{{ $incident->website->name }}</td>
                    <td class="px-4 py-3 text-text-muted">{{ $incident->type }}</td>
                    <td class="px-4 py-3">{{ $incident->severity }}</td>
                    <td class="px-4 py-3">{{ $incident->status }}</td>
                    <td class="px-4 py-3 text-text-muted">{{ $incident->detected_at?->diffForHumans() }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.incidents.show', $incident) }}" class="text-text-muted underline hover:text-text">{{ __('View') }}</a>
                    </td>
                </tr>
            @empty
                <x-ui.table-empty :columns="6" :title="__('No incidents found.')" />
            @endforelse
        </x-ui.table-body>
    </x-ui.table>

    <div class="mt-4 flex items-center justify-between gap-4">
        <x-per-page />
        <div>{{ $incidents->links() }}</div>
    </div>
</x-admin-layout>
