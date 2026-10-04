<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Phase 4: render the component `title` slot into <title> (the legacy
         `@yield('title')` never received it). Falls back to the default. --}}
    <title>{{ isset($title) ? $title : 'SiteSentinel — Admin' }}</title>
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
    <a href="#admin-content"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-surface-elevated focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-text focus:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
        {{ __('Skip to content') }}
    </a>

    <div x-data="sidebar" id="admin-shell" class="min-h-screen" x-bind:class="collapsed ? 'is-collapsed' : ''">
        {{-- Desktop sidebar (fixed). Collapses to icons via `#admin-shell.is-collapsed`. --}}
        <aside id="admin-sidebar"
               aria-label="{{ __('Primary') }}"
               class="hidden border-r border-border bg-surface-elevated md:fixed md:inset-y-0 md:left-0 md:z-30 md:block">
            <x-admin-sidebar />
        </aside>

        {{-- Mobile off-canvas drawer: opened by the top-bar hamburger. --}}
        <div x-show="mobileOpen" x-cloak class="fixed inset-0 z-50 md:hidden">
            <div x-show="mobileOpen" x-transition.opacity x-on:click="closeDrawer()"
                 class="fixed inset-0 bg-text/50" aria-hidden="true"></div>

            <div x-ref="drawer"
                 x-show="mobileOpen"
                 x-transition
                 x-on:keydown.escape.prevent="closeDrawer()"
                 x-on:keydown.tab="trap($event)"
                 x-on:click="closeDrawer(false)"
                 id="admin-nav-drawer"
                 role="dialog"
                 aria-modal="true"
                 aria-label="{{ __('Primary') }}"
                 tabindex="-1"
                 class="fixed inset-y-0 left-0 z-50 w-64 border-r border-border bg-surface-elevated shadow-xl focus:outline-none">
                <button type="button"
                        x-on:click="closeDrawer()"
                        aria-label="{{ __('Close navigation') }}"
                        title="{{ __('Close navigation') }}"
                        class="absolute right-3 top-4 z-10 rounded-md p-1.5 text-text-muted hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>

                <x-admin-sidebar />
            </div>
        </div>

        {{-- Right column: top bar + content. --}}
        <div id="admin-main" class="min-w-0">
            <header class="sticky top-0 z-20 border-b border-border bg-surface-elevated">
                <div class="flex h-16 items-center gap-2 px-4">
                    {{-- Hamburger — mobile only. Icon-only: the accessible name is
                         the aria-label, so this button carries no text node. --}}
                    <button type="button"
                            data-drawer-toggle
                            x-on:click="openDrawer()"
                            aria-label="{{ __('Open navigation') }}"
                            aria-controls="admin-nav-drawer"
                            aria-expanded="false"
                            x-bind:aria-expanded="mobileOpen ? 'true' : 'false'"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-text-muted hover:bg-surface-hover hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus md:hidden">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    {{-- Brand / page area. --}}
                    <a href="{{ route('admin.dashboard') }}"
                       class="shrink-0 text-base font-bold tracking-tight text-text rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus">
                        {{ __('SiteSentinel') }}
                    </a>

                    <div class="min-w-0 flex-1"></div>

                    {{-- Requirement 27: the sidebar collapse/expand control moved to
                         the bottom of the sidebar itself (x-admin-sidebar). --}}
                    <x-theme-switcher />

                    {{-- Requirement 28 (Phase H): in-app notification centre bell,
                         between the theme switcher and the profile menu. --}}
                    <x-notification-center />

                    <x-profile-dropdown />
                </div>
            </header>

            <main id="admin-content" class="mx-auto max-w-7xl px-4 py-6">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
