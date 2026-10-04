@props([
    'class' => '',
])

{{--
    In-app notification centre — bell + unread badge + dropdown panel
    (Requirement 28 / Phase H, ADR-038, NOTIFICATIONS.md §15).

    A thin client over the EXISTING Phase G JSON endpoints
    (`admin.notifications.in-app.*`). The component logic lives in
    `resources/js/app.js` (`notificationCenter`), never inline (AGENTS.md §7).
    Polling is independent from the visual panel: the component polls the cheap
    `unread-count` endpoint on a fixed interval (non-overlapping, paused while
    the tab is hidden) and loads the recent list lazily on first open. This is
    NOT real-time — generation is backend (ADR-038).

    Positioning: on narrow screens the panel is a fixed, full-width sheet below
    the header (no horizontal overflow); from `sm` up it anchors to the trigger
    (`right-0`) like `theme-switcher` / `profile-dropdown`, capped to the
    viewport width.
--}}
<div
    x-data="notificationCenter({
        indexUrl: @js(route('admin.notifications.in-app.index')),
        unreadCountUrl: @js(route('admin.notifications.in-app.unread-count')),
        readAllUrl: @js(route('admin.notifications.read-all')),
        markReadUrlTemplate: @js(route('admin.notifications.read', ['adminNotification' => '__ID__'])),
        fullPageUrl: @js(route('admin.notifications.in-app.page')),
        csrfToken: @js(csrf_token()),
        pollInterval: 60000,
        messages: {
            unread: @js(__('unread')),
            title: @js(__('Notifications')),
            markAll: @js(__('Mark all as read')),
            showAll: @js(__('Show all notifications')),
            markRead: @js(__('Read')),
            empty: @js(__('No notifications')),
            emptyHint: @js(__('You are all caught up.')),
            error: @js(__('Could not load notifications.')),
            retry: @js(__('Retry')),
        },
    })"
    x-on:click.outside="closeMenu()"
    x-on:keydown.escape.window="open && closeMenu(true)"
    {{ $attributes->merge(['class' => 'relative inline-block '.$class]) }}
>
    <button
        type="button"
        data-notification-trigger
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown="onTriggerKeydown($event)"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-bind:aria-label="@js(__('Notifications')) + (unreadCount ? ' (' + countLabel + ')' : '')"
        aria-label="{{ __('Notifications') }}"
        aria-haspopup="true"
        aria-expanded="false"
        title="{{ __('Notifications') }}"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-md border border-border-muted bg-surface-elevated text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"
    >
        <x-ui.icon name="bell" x-show="unreadCount === 0" />
        <x-ui.icon name="bell-alert" x-show="unreadCount > 0" x-cloak />

        {{-- Unread badge: hidden at zero; caps the DISPLAY at 99+ while the real
             count stays in the component (and the accessible label). --}}
        <span
            x-show="unreadCount > 0"
            x-cloak
            data-notification-badge
            class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold leading-none text-danger-foreground"
            x-text="badgeText"
            aria-hidden="true"
        ></span>
    </button>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-transition.origin.top.right
        role="region"
        aria-label="{{ __('Notifications') }}"
        class="fixed inset-x-4 top-16 z-50 max-h-[70vh] overflow-hidden rounded-lg border border-border bg-surface-elevated shadow-lg focus:outline-none sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 sm:max-w-[calc(100vw-2rem)]"
    >
        {{-- Header: title + unread count --}}
        <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-text">{{ __('Notifications') }}</p>
                <p class="text-xs text-text-muted" data-notification-count>
                    <span x-show="unreadCount > 0"><span x-text="unreadCount"></span> {{ __('unread') }}</span>
                    <span x-show="unreadCount === 0" x-cloak>{{ __('You are all caught up.') }}</span>
                </p>
            </div>
        </div>

        {{-- Loading state --}}
        <div x-show="loading" x-cloak data-notification-loading class="p-4">
            <x-ui.skeleton :lines="3" />
        </div>

        {{-- Error state --}}
        <div x-show="error" x-cloak data-notification-error class="px-4 py-8 text-center">
            <p class="text-sm font-medium text-danger">{{ __('Could not load notifications.') }}</p>
            <button
                type="button"
                x-on:click="load()"
                class="mt-2 rounded-md text-sm text-text-muted underline hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"
            >
                {{ __('Retry') }}
            </button>
        </div>

        {{-- Empty state --}}
        <div x-show="! loading && ! error && items.length === 0" x-cloak data-notification-empty>
            <x-ui.empty-state :title="__('No notifications')" :description="__('You are all caught up.')">
                <x-slot name="icon">
                    <x-ui.icon name="bell" class="h-8 w-8" />
                </x-slot>
            </x-ui.empty-state>
        </div>

        {{-- Recent list --}}
        <ul
            x-show="! loading && ! error && items.length > 0"
            x-cloak
            data-notification-list
            class="max-h-96 divide-y divide-border overflow-y-auto"
        >
            <template x-for="item in items" :key="item.id">
                <li
                    class="relative flex items-start gap-3 px-4 py-3 transition-colors hover:bg-surface-hover"
                    data-notification-row
                    x-bind:data-read="item.read ? 'true' : 'false'"
                    x-bind:class="item.read ? '' : 'bg-info-muted/40'"
                >
                    <span class="mt-0.5 shrink-0" x-bind:class="item.iconColor" aria-hidden="true">
                        <x-ui.icon name="check-circle" x-show="item.icon === 'check-circle'" />
                        <x-ui.icon name="exclamation-triangle" x-show="item.icon === 'exclamation-triangle'" x-cloak />
                        <x-ui.icon name="x-circle" x-show="item.icon === 'x-circle'" x-cloak />
                        <x-ui.icon name="bell" x-show="item.icon === 'bell'" x-cloak />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate pr-10 text-sm font-medium text-text" x-text="item.title"></p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-text-muted" x-show="item.body" x-text="item.body"></p>
                        <p class="mt-1 text-xs text-text-subtle" x-text="item.time"></p>
                    </div>

                    {{-- Individual mark-as-read. Sits above the stretched link so
                         it stays clickable when the row also navigates. --}}
                    <button
                        type="button"
                        x-show="! item.read"
                        x-cloak
                        x-on:click.stop="markRead(item.id)"
                        x-bind:disabled="busy"
                        class="relative z-10 shrink-0 rounded-md border border-border-muted px-2 py-1 text-xs font-medium text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-50"
                    >
                        {{ __('Read') }}
                    </button>

                    {{-- Stretched link: only rendered for a validated internal
                         relative path (never an external URL). --}}
                    <template x-if="item.link">
                        <a x-bind:href="item.link" x-bind:aria-label="item.title" class="absolute inset-0 z-0"></a>
                    </template>
                </li>
            </template>
        </ul>

        {{-- Footer actions --}}
        <div class="flex items-center justify-between gap-2 border-t border-border px-4 py-2">
            <button
                type="button"
                data-notification-mark-all
                x-on:click="markAllRead()"
                x-bind:disabled="busy || unreadCount === 0"
                class="rounded-md text-xs font-medium text-text-muted transition-colors hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-50"
            >
                {{ __('Mark all as read') }}
            </button>

            <a
                href="{{ route('admin.notifications.in-app.page') }}"
                data-notification-show-all
                class="rounded-md text-xs font-medium text-text-muted transition-colors hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus"
            >
                {{ __('Show all notifications') }}
            </a>
        </div>
    </div>
</div>
