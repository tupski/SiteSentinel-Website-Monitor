@props([
    'title' => null,
])

@php
    // Dedicated documentation shell (separate from the admin app shell). It
    // mirrors the Laravel framework-docs chrome: its own header with logo,
    // search, version selector and theme toggle, a grouped left sidebar, a
    // centred content column and a right "On this page" table of contents.
    //
    // Layout: single column on mobile (sidebar + TOC live in a drawer), two
    // columns from `lg` (sidebar + content) and three columns from `xl`
    // (sidebar + content + TOC).
    $appName = $siteName ?? config('app.name', 'SiteSentinel');
    $docsVersion = (string) config('documentation.version', '0.1.x');
    $docsVersions = (array) config('documentation.versions', []);
    $groups = (array) config('documentation.groups', []);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — '.$appName.' docs' : $appName.' — Documentation' }}</title>
    @if (! empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
    <a href="#docs-content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-surface-elevated focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-text focus:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
        {{ __('Skip to content') }}
    </a>

    <div id="docs-shell" x-data="docsShell" class="min-h-screen">
        {{-- Sticky docs header. --}}
        <header class="sticky top-0 z-40 border-b border-border bg-surface-elevated">
            <div class="mx-auto flex h-16 max-w-screen-2xl items-center gap-3 px-4 lg:px-6">
                {{-- Mobile/tablet navigation toggle — opens the sidebar+TOC drawer. --}}
                <button type="button"
                        data-docs-menu-toggle
                        x-on:click="openDrawer()"
                        aria-label="{{ __('Open documentation navigation') }}"
                        aria-controls="docs-nav-drawer"
                        aria-expanded="false"
                        x-bind:aria-expanded="sidebarOpen ? 'true' : 'false'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border-muted bg-surface-elevated text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus lg:hidden">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                </button>

                {{-- Brand — links back to the docs home. --}}
                <a href="{{ route('admin.documentation') }}"
                   class="flex shrink-0 items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus"
                   title="{{ $appName }}">
                    <span class="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-md bg-primary text-primary-foreground" aria-hidden="true">
                        @if (! empty($siteLogoUrl))
                            <img src="{{ $siteLogoUrl }}" alt="" class="h-6 w-auto" aria-hidden="true">
                        @endif
                        <x-ui.icon name="book" class="h-5 w-5" />
                    </span>
                    <span class="text-sm font-semibold text-text">{{ $appName }}</span>
                    <span class="hidden text-sm text-text-subtle sm:inline">{{ __('Docs') }}</span>
                </a>

                {{-- Client-side search. --}}
                <div class="relative ml-auto w-full max-w-xs sm:max-w-sm"
                     x-on:keydown.escape="q = ''">
                    <label for="docs-search" class="sr-only">{{ __('Search documentation') }}</label>
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-text-subtle" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                        </svg>
                    </span>
                    <input id="docs-search"
                           type="search"
                           x-model.debounce.150ms="q"
                           autocomplete="off"
                           placeholder="{{ __('Search docs...') }}"
                           class="block w-full rounded-md border border-border-muted bg-surface pl-9 pr-3 py-2 text-sm text-text placeholder:text-text-subtle focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus" />
                </div>

                {{-- Version selector (presentational — see config/documentation.php). --}}
                <div class="hidden shrink-0 items-center gap-1 md:flex">
                    <label for="docs-version" class="sr-only">{{ __('Documentation version') }}</label>
                    <select id="docs-version"
                            class="rounded-md border border-border-muted bg-surface-elevated py-2 pl-3 pr-8 text-sm text-text focus:border-focus focus:outline-none focus:ring-2 focus:ring-focus">
                        @foreach ($docsVersions as $option)
                            @php($label = $option['label'] ?? $docsVersion)
                            <option value="{{ $label }}" @selected(($option['current'] ?? false) || $label === $docsVersion)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Return to the admin application. --}}
                <a href="{{ route('admin.dashboard') }}"
                   class="hidden shrink-0 items-center gap-1.5 rounded-md border border-border-muted px-3 py-2 text-sm font-medium text-text-muted transition-colors hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus sm:inline-flex">
                    <x-ui.icon name="chevron-left" class="h-4 w-4" />
                    {{ __('App') }}
                </a>

                <x-theme-switcher class="shrink-0" />
            </div>
        </header>

        {{-- Mobile/tablet off-canvas drawer: grouped nav + "On this page" TOC. --}}
        <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-50 lg:hidden">
            <div x-show="sidebarOpen" x-transition.opacity x-on:click="closeDrawer()"
                 class="fixed inset-0 bg-text/50" aria-hidden="true"></div>

            <div x-ref="drawer"
                 x-show="sidebarOpen"
                 x-transition
                 x-on:keydown.escape.prevent="closeDrawer()"
                 x-on:keydown.tab="trap($event)"
                 x-on:click="closeDrawer(false)"
                 id="docs-nav-drawer"
                 role="dialog"
                 aria-modal="true"
                 aria-label="{{ __('Documentation navigation') }}"
                 tabindex="-1"
                 class="docs-drawer fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] overflow-y-auto border-r border-border bg-surface-elevated px-4 py-4 shadow-xl focus:outline-none">
                <div class="mb-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-text">{{ __('Documentation') }}</span>
                    <button type="button"
                            x-on:click="closeDrawer()"
                            aria-label="{{ __('Close documentation navigation') }}"
                            title="{{ __('Close documentation navigation') }}"
                            class="rounded-md p-1.5 text-text-muted hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                        <x-ui.icon name="x-mark" class="h-5 w-5" />
                    </button>
                </div>

                @include('docs.partials.nav')
                @include('docs.partials.toc')
            </div>
        </div>

        {{-- Content grid: 1 col (mobile) → 2 cols (lg) → 3 cols (xl). --}}
        <div class="mx-auto max-w-screen-2xl px-4 lg:px-6">
            <div data-docs-grid
                 class="py-6 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[16rem_minmax(0,1fr)_16rem]">
                {{-- Left: grouped navigation (desktop). --}}
                <aside data-docs-sidebar
                       aria-label="{{ __('Documentation') }}"
                       class="hidden lg:block">
                    <div class="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:pr-2">
                        @include('docs.partials.nav')
                    </div>
                </aside>

                {{-- Centre: content column. --}}
                <main id="docs-content" class="min-w-0">
                    @if (isset($slot) && trim((string) $slot) !== '')
                        {{ $slot }}
                    @endif
                </main>

                {{-- Right: "On this page" TOC (desktop xl only). --}}
                <aside data-docs-toc
                       aria-label="{{ __('On this page') }}"
                       class="hidden xl:block">
                    <div class="xl:sticky xl:top-20 xl:max-h-[calc(100vh-6rem)] xl:overflow-y-auto">
                        @include('docs.partials.toc')
                    </div>
                </aside>
            </div>
        </div>
    </div>
</body>
</html>
