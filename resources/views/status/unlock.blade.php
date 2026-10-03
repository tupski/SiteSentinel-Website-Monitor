<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Status — Unlock</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<main class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-xl font-bold">Status page locked</h1>
    <p class="mt-2 text-sm text-slate-600">Enter password for access.</p>
    @if($errors->any())
        <div class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {{ e($errors->first()) }}
        </div>
    @endif
    <form method="POST" action="{{ route('status.unlock') }}" class="mt-4 rounded border border-slate-200 bg-white p-4">
        @csrf
        <label for="password" class="block text-sm font-medium">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        <button type="submit" class="mt-3 w-full rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Unlock</button>
    </form>
</main>
</body>
</html>
