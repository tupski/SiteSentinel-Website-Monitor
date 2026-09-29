<x-app-layout>
    <x-slot name="title">{{ __('Sign in') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-bold tracking-tight">SiteSentinel</h1>
            <p class="mt-1 text-sm text-slate-600">Admin sign-in</p>

            @if (session('status'))
                <div class="mt-4 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">{{ __('Password') }}</label>
                    <input id="password" name="password" type="password" required
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                        class="w-full rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500">
                    {{ __('Sign in') }}
                </button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a href="{{ route('password.request') }}" class="text-slate-600 underline hover:text-slate-900">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        </div>
    </div>
</x-app-layout>
