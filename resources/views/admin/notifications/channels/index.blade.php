<x-admin-layout>
    <x-slot name="title">{{ __('Notification') }} — SiteSentinel</x-slot>

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Notification') }}</h1>
        <x-ui.button :href="route('admin.notifications.create')" variant="primary">
            {{ __('Add channel') }}
        </x-ui.button>
    </div>

    @if (session('status'))
        <x-ui.alert variant="success" class="mb-4">
            {{ session('status') }}
        </x-ui.alert>
    @endif

    <x-ui.table>
        <x-ui.table-head>
            <tr>
                <th class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Type') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Enabled') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Failed sends') }}</th>
                <th class="px-4 py-3 text-left font-medium">{{ __('Actions') }}</th>
            </tr>
        </x-ui.table-head>
        <x-ui.table-body>
            @forelse ($channels as $channel)
                <tr>
                    <td class="px-4 py-3 font-medium text-text">{{ $channel->name }}</td>
                    <td class="px-4 py-3">{{ $channel->type }}</td>
                    <td class="px-4 py-3">{{ $channel->enabled ? __('Yes') : __('No') }}</td>
                    <td class="px-4 py-3">{{ $channel->failed_count ?? 0 }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.notifications.edit', $channel) }}" class="mr-3 text-text-muted underline hover:text-text">{{ __('Edit') }}</a>
                        <form method="POST" action="{{ route('admin.notifications.test-send', $channel) }}" class="mr-3 inline-block">
                            @csrf
                            <button type="submit" class="text-text-muted underline hover:text-text">{{ __('Send test') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.notifications.destroy', $channel) }}" class="inline-block" onsubmit="return confirm('{{ __('Delete this channel?') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-danger underline hover:text-danger-hover">{{ __('Delete') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <x-ui.table-empty :columns="5" :title="__('No channels configured yet.')" />
            @endforelse
        </x-ui.table-body>
    </x-ui.table>

    @include('admin.notifications.channels._push', [
        'pushPublicKey' => $pushPublicKey ?? '',
        'pushEnabled' => $pushEnabled ?? false,
    ])

    <div class="mt-4">
        <a href="{{ route('admin.notification-logs.index') }}" class="text-sm text-text-muted underline hover:text-text">{{ __('View delivery log') }}</a>
    </div>
</x-admin-layout>
