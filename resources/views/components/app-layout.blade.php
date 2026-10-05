<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', ($siteName ?? config('app.name', 'SiteSentinel')))</title>
    @if (! empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif
    @include('partials.theme-bootstrap')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-text antialiased">
    <main>
        {{ $slot }}
    </main>
</body>
</html>
