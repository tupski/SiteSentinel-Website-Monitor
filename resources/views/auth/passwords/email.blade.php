<x-app-layout>
    <x-slot name="title">{{ __('Reset password') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Forgot your password?') }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                {{ __('Enter your admin email and we will send a reset link.') }}
            </p>

            @if (session('status'))
                <div class="mt-4 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                        class="w-full rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ __('Send reset link') }}
                </button>
            </form>

            <p class="mt-4 text-center text-sm">
                <a href="{{ route('login') }}" class="text-slate-600 underline hover:text-slate-900">{{ __('Back to sign in') }}</a>
            </p>
        </div>
    </div>
</x-app-layout>
