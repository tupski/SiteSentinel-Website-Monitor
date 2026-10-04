<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SiteSentinel — Admin')</title>
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
    <header class="border-b border-border bg-surface-elevated">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-bold tracking-tight text-text">SiteSentinel</a>
                <nav class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <a href="{{ route('admin.dashboard') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Dashboard') }}</a>
                    <a href="{{ route('admin.websites.index') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Websites') }}</a>
                    <a href="{{ route('admin.incidents.index') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Incidents') }}</a>
                    <a href="{{ route('admin.notifications.index') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Notification') }}</a>
                    <a href="{{ route('admin.notification-logs.index') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Delivery log') }}</a>
                    <a href="{{ route('admin.status-pages.index') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Status pages') }}</a>
                    <a href="{{ route('admin.settings.edit') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Settings') }}</a>
                    <a href="{{ route('admin.documentation') }}" class="text-text-muted hover:text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-elevated">{{ __('Documentation') }}</a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('admin.profile.edit') }}" class="text-sm text-text-muted hover:text-text">{{ __('Profile') }}</a>
                <x-theme-switcher />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</x-ui.button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">
        {{ $slot }}
    </main>
</body>
</html>
