@php
    // Real route names only (Phase 4) — grouped to mirror the documentation
    // navigation table. `active` matches the route against the request so the
    // marked item keeps working in both expanded and collapsed modes.
    $navGroups = [
        [
            'label' => null,
            'items' => [
                ['route' => 'admin.dashboard', 'label' => __('Dashboard'), 'icon' => 'home'],
            ],
        ],
        [
            'label' => __('Monitoring'),
            'items' => [
                ['route' => 'admin.websites.index', 'label' => __('Websites'), 'icon' => 'globe'],
                ['route' => 'admin.incidents.index', 'label' => __('Incidents'), 'icon' => 'alert'],
            ],
        ],
        [
            'label' => __('Notifications'),
            'items' => [
                ['route' => 'admin.notifications.index', 'label' => __('Notification'), 'icon' => 'bell'],
                ['route' => 'admin.notification-logs.index', 'label' => __('Delivery log'), 'icon' => 'list'],
            ],
        ],
        [
            'label' => null,
            'items' => [
                ['route' => 'admin.status-pages.index', 'label' => __('Status pages'), 'icon' => 'signal'],
            ],
        ],
        [
            'label' => __('Administration'),
            'items' => [
                ['route' => 'admin.profile.edit', 'label' => __('Profile'), 'icon' => 'user'],
                ['route' => 'admin.settings.edit', 'label' => __('Settings'), 'icon' => 'cog'],
                ['route' => 'admin.documentation', 'label' => __('Documentation'), 'icon' => 'book'],
            ],
        ],
    ];

    $isActive = static fn (string $route): bool => request()->routeIs($route);
@endphp

<div class="flex h-full min-h-0 flex-col bg-surface-elevated">
    {{-- Brand row --}}
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-border px-4">
        @php($appName = $siteName ?? config('app.name', 'SiteSentinel'))
        <a href="{{ route('admin.dashboard') }}"
           class="flex items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus"
           title="{{ $appName }}">
            <span class="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-md bg-primary text-primary-foreground" aria-hidden="true">
                @if (! empty($siteLogoUrl))
                    <img src="{{ $siteLogoUrl }}" alt="" class="h-8 w-8 object-contain">
                @else
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3 4 6v6c0 4.418 3.4 8.4 8 9 4.6-.6 8-4.582 8-9V6l-8-3Z" />
                        <path d="M9.5 12.2l1.8 1.8 3.4-3.6" />
                    </svg>
                @endif
            </span>
            <span class="truncate text-base font-bold tracking-tight text-text" data-sidebar-label>{{ $appName }}</span>
        </a>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-2 py-4">
        @foreach ($navGroups as $group)
            <div @class(['mb-4', 'mt-4 border-t border-border pt-4' => $group['label'] && !$loop->first])>
                @if ($group['label'])
                    <div class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-text-subtle" data-sidebar-label>
                        {{ $group['label'] }}
                    </div>
                @endif

                <ul class="space-y-1">
                    @foreach ($group['items'] as $item)
                        @php($active = $isActive($item['route']))
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if ($active) aria-current="page" @endif
                               aria-label="{{ $item['label'] }}"
                               title="{{ $item['label'] }}"
                               data-nav-item
                               @class([
                                   'group relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                                   'text-text-muted hover:bg-surface-hover hover:text-text' => ! $active,
                                   'bg-surface-hover font-semibold text-text' => $active,
                                   'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus',
                               ])>
                                {{-- Non-colour active indicator: a left bar + heavier weight. --}}
                                @if ($active)
                                    <span class="absolute inset-y-1.5 left-0 w-1 rounded-full bg-focus" aria-hidden="true" data-active-indicator></span>
                                @endif

                                <span class="shrink-0" aria-hidden="true">
                                    @switch($item['icon'])
                                        @case('home')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 10.5 12 3l9 7.5" /><path d="M5.25 9.75V21h13.5V9.75" /><path d="M9.75 21v-6h4.5v6" />
                                            </svg>
                                            @break
                                        @case('globe')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18Z" />
                                            </svg>
                                            @break
                                        @case('alert')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /><path d="M12 9v4" /><path d="M12 17h.01" />
                                            </svg>
                                            @break
                                        @case('bell')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                                            </svg>
                                            @break
                                        @case('list')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M8 6h13" /><path d="M8 12h13" /><path d="M8 18h13" /><path d="M3 6h.01M3 12h.01M3 18h.01" />
                                            </svg>
                                            @break
                                        @case('signal')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M2 20h.01" /><path d="M7 20v-4" /><path d="M12 20v-8" /><path d="M17 20V8" /><path d="M22 4v16" />
                                            </svg>
                                            @break
                                        @case('user')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 21a8 8 0 0 0-16 0" /><circle cx="12" cy="8" r="4" />
                                            </svg>
                                            @break
                                        @case('cog')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
                                            </svg>
                                            @break
                                        @case('book')
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
                                            </svg>
                                            @break
                                    @endswitch
                                </span>

                                <span class="truncate" data-sidebar-label>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    {{-- Requirement 27: collapse/expand control pinned to the BOTTOM of the
         sidebar. It is a non-scrolling footer sibling of the scrollable nav
         (`min-h-0 flex-1 overflow-y-auto` above), so it can never overlap the
         nav items or any other sidebar content. Desktop only (`md:flex`): the
         collapsed state only affects the md+ rail, and on mobile the off-canvas
         drawer keeps its own dedicated close control in the shell. --}}
    <div class="shrink-0 border-t border-border p-2">
        <button type="button"
                data-sidebar-collapse
                x-on:click="toggleCollapse()"
                x-bind:aria-expanded="collapsed ? 'false' : 'true'"
                x-bind:aria-label="collapseLabel()"
                x-bind:title="collapseLabel()"
                aria-label="{{ __('Collapse sidebar') }}"
                aria-controls="admin-sidebar"
                title="{{ __('Collapse sidebar') }}"
                class="hidden w-full items-center gap-3 rounded-md px-3 py-2 text-sm text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus md:flex">
            <span class="shrink-0" aria-hidden="true">
                <x-ui.icon name="chevron-double-left" class="h-5 w-5" data-collapse-icon="collapse" x-show="!collapsed" />
                <x-ui.icon name="chevron-double-right" class="h-5 w-5" data-collapse-icon="expand" x-show="collapsed" x-cloak />
            </span>
            <span class="truncate" data-sidebar-label>{{ __('Collapse sidebar') }}</span>
        </button>
    </div>
</div>
