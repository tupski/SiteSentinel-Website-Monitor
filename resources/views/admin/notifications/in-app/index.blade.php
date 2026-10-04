<x-admin-layout>
    <x-slot name="title">{{ __('Notifications') }} — SiteSentinel Admin</x-slot>

    {{--
        Full-page in-app notification centre (Requirement 28 / Phase H, ADR-038,
        NOTIFICATIONS.md §15). Fully server-rendered and correct without
        JavaScript; the `notificationList` Alpine component (resources/js/app.js)
        only upgrades the mark-read controls to the EXISTING Phase G JSON
        endpoints and updates the visible unread count in place.
    --}}
    <div
        x-data="notificationList({
            readAllUrl: @js(route('admin.notifications.read-all')),
            csrfToken: @js(csrf_token()),
            messages: {
                unread: @js(__('unread')),
                failed: @js(__('Could not mark the notification as read. Please try again.')),
                failedAll: @js(__('Could not mark all notifications as read. Please try again.')),
            },
        })"
    >
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Notifications') }}</h1>
                <p class="mt-1 text-sm text-text-muted" data-notification-list-count>
                    {{ $unreadCount }} {{ __('unread') }}
                </p>
            </div>

            <x-ui.button
                type="button"
                variant="secondary"
                size="sm"
                data-notification-mark-all
                x-on:click="markAllRead()"
                x-bind:disabled="busy"
            >
                {{ __('Mark all as read') }}
            </x-ui.button>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" class="mb-4" :dismissible="true">
                {{ session('status') }}
            </x-ui.alert>
        @endif

        {{-- Inline error surfaced honestly by the Alpine enhancement. --}}
        <div
            data-notification-list-error
            class="mb-4 hidden rounded border border-danger/30 bg-danger-muted px-4 py-3 text-sm text-danger"
            role="alert"
        ></div>

        {{-- Filters: status + type, whitelisted server-side. --}}
        <form method="GET" action="{{ route('admin.notifications.in-app.page') }}" class="mb-4 flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-text-muted">{{ __('Status') }}</span>
                <x-ui.select name="status" class="mt-1 !w-auto">
                    <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                    <option value="unread" @selected($status === 'unread')>{{ __('Unread') }}</option>
                    <option value="read" @selected($status === 'read')>{{ __('Read') }}</option>
                </x-ui.select>
            </label>

            <label class="text-sm">
                <span class="block text-text-muted">{{ __('Type') }}</span>
                <x-ui.select name="type" class="mt-1 !w-auto">
                    <option value="" @selected($type === '')>{{ __('All') }}</option>
                    @foreach ($types as $option)
                        <option value="{{ $option }}" @selected($type === $option)>{{ $option }}</option>
                    @endforeach
                </x-ui.select>
            </label>

            <x-ui.button type="submit" variant="primary" size="sm">{{ __('Filter') }}</x-ui.button>
        </form>

        <x-ui.table>
            <x-ui.table-head>
                <tr>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Notification') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Received') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Actions') }}</th>
                </tr>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($notifications as $notification)
                    @php($isUnread = $notification->read_at === null)
                    <tr
                        data-notification-row
                        data-read="{{ $isUnread ? 'false' : 'true' }}"
                        class="{{ $isUnread ? 'bg-info-muted/40' : '' }}"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 shrink-0 {{ [
                                    'success' => 'text-success',
                                    'warning' => 'text-warning',
                                    'danger' => 'text-danger',
                                    'info' => 'text-info',
                                ][$notification->severity] ?? 'text-text-subtle' }}">
                                    @switch($notification->severity)
                                        @case('success')
                                            <x-ui.icon name="check-circle" />
                                            @break
                                        @case('warning')
                                            <x-ui.icon name="exclamation-triangle" />
                                            @break
                                        @case('danger')
                                            <x-ui.icon name="x-circle" />
                                            @break
                                        @default
                                            <x-ui.icon name="bell" />
                                    @endswitch
                                </span>
                                <div class="min-w-0">
                                    <p class="font-medium text-text">{{ $notification->title }}</p>
                                    @if ($notification->body)
                                        <p class="mt-0.5 max-w-prose text-sm text-text-muted">{{ $notification->body }}</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-text-muted">{{ $notification->type }}</td>
                        <td class="px-4 py-3">
                            <span data-notification-status="unread" class="{{ $isUnread ? '' : 'hidden' }}">
                                <x-ui.badge variant="info">{{ __('Unread') }}</x-ui.badge>
                            </span>
                            <span data-notification-status="read" class="{{ $isUnread ? 'hidden' : '' }}">
                                <x-ui.badge variant="neutral">{{ __('Read') }}</x-ui.badge>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-text-muted">
                            <time datetime="{{ $notification->created_at?->toIso8601String() }}">
                                {{ $notification->created_at?->diffForHumans() }}
                            </time>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-3">
                                @if ($isUnread)
                                    <button
                                        type="button"
                                        data-notification-read
                                        data-url="{{ route('admin.notifications.read', $notification) }}"
                                        x-on:click="markRead($event)"
                                        class="rounded-md text-sm text-text-muted underline hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"
                                    >
                                        {{ __('Mark as read') }}
                                    </button>
                                @endif

                                @if ($notification->safe_link)
                                    <a href="{{ $notification->safe_link }}" class="rounded-md text-sm text-text-muted underline hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                                        {{ __('Open') }}
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.table-empty :columns="5" :title="__('No notifications')" :description="__('You are all caught up.')" />
                @endforelse
            </x-ui.table-body>
        </x-ui.table>

        <div class="mt-4 flex items-center justify-between gap-4">
            <x-per-page />
            <div>{{ $notifications->links() }}</div>
        </div>
    </div>
</x-admin-layout>
