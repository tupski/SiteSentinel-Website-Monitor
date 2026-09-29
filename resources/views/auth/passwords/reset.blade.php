<x-app-layout>
    <x-slot name="title">{{ __('Choose a new password') }} — SiteSentinel</x-slot>

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-bold tracking-tight">{{ __('Choose a new password') }}</h1>

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">{{ __('New password') }}</label>
                    <input id="password" name="password" type="password" required minlength="12"
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                    <p class="mt-1 text-xs text-slate-500">{{ __('Minimum 12 characters.') }}</p>
                    @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700">{{ __('Confirm new password') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12"
                           class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
                </div>

                <button type="submit"
                        class="w-full rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    {{ __('Reset password') }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
