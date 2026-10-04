<x-admin-layout>
    <x-slot name="title">{{ __('Incidents') }} — SiteSentinel Admin</x-slot>

    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">{{ __('Incidents') }}</h1>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- FR-62: filterable by state, severity, type, and website. --}}
    <form method="GET" action="{{ route('admin.incidents.index') }}" class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="block text-slate-600">{{ __('Status') }}</span>
            <select name="status" class="mt-1 rounded border-slate-300 text-sm">
                <option value="">{{ __('All') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-slate-600">{{ __('Severity') }}</span>
            <select name="severity" class="mt-1 rounded border-slate-300 text-sm">
                <option value="">{{ __('All') }}</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity }}" @selected(request('severity') === $severity)>{{ $severity }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-slate-600">{{ __('Type') }}</span>
            <select name="type" class="mt-1 rounded border-slate-300 text-sm">
                <option value="">{{ __('All') }}</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-800">{{ __('Filter') }}</button>
    </form>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Website') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Severity') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Detected') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-700">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($incidents as $incident)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $incident->website->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $incident->type }}</td>
                        <td class="px-4 py-3">{{ $incident->severity }}</td>
                        <td class="px-4 py-3">{{ $incident->status }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $incident->detected_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.incidents.show', $incident) }}" class="text-slate-600 underline hover:text-slate-900">{{ __('View') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('No incidents found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between gap-4">
        <x-per-page />
        <div>{{ $incidents->links() }}</div>
    </div>
</x-admin-layout>
