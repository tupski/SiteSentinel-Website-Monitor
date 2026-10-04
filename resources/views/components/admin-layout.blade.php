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
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-900 dark:text-slate-200">
    <header class="border-b border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
            <div class="flex items-center gap-6">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-bold tracking-tight">SiteSentinel</a>
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('admin.dashboard') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Dashboard') }}</a>
                    <a href="{{ route('admin.websites.index') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Websites') }}</a>
                    <a href="{{ route('admin.incidents.index') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Incidents') }}</a>
                    <a href="{{ route('admin.notifications.index') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Notification') }}</a>
                    <a href="{{ route('admin.notification-logs.index') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Delivery log') }}</a>
                    <a href="{{ route('admin.status-pages.index') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Status pages') }}</a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <x-theme-switcher />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-slate-600 underline hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">
        {{ $slot }}
    </main>
</body>
</html>
