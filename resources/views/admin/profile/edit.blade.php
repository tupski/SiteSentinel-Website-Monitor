@php
    $minPassword = max(12, (int) config('sentinel.auth.min_password_length', 12));
    $initials = collect(preg_split('/\s+/', trim((string) $user->name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<x-admin-layout>
    <x-slot name="title">{{ __('Profile') }} — SiteSentinel</x-slot>

    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex items-center gap-4">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary text-base font-semibold text-primary-foreground"
                  aria-hidden="true">{{ $initials }}</span>
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-text">{{ __('Profile') }}</h1>
                <p class="text-sm text-text-muted">{{ __('Manage your account identity and password.') }}</p>
            </div>
        </div>

        @if (session('status'))
            <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Please correct the errors below.') }}</p>
            </x-ui.alert>
        @endif

        <x-ui.card :title="__('Profile Information')" :subtitle="__('The name and email address shown across the admin shell.')">
            <form method="POST" action="{{ route('admin.profile.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <x-form.field id="name" name="name" :label="__('Name')" required
                              hint="{{ __('Displayed in the admin shell.') }}"
                              help="{{ __('The operator name shown in listings and the header. Maximum 255 characters.') }}">
                    <x-ui.input id="name" name="name" type="text" autocomplete="name"
                           :value="old('name', $user->name)" required
                           aria-describedby="name-hint name-error" :invalid="$errors->has('name')" />
                </x-form.field>

                <x-form.field id="email" name="email" :label="__('Email')" required
                              hint="{{ __('Used to sign in and receive alerts.') }}"
                              help="{{ __('Changing this changes the address you sign in with. It must be unique and a valid email address.') }}">
                    <x-ui.input id="email" name="email" type="email" autocomplete="email"
                           :value="old('email', $user->email)" required
                           aria-describedby="email-hint email-error" :invalid="$errors->has('email')" />
                </x-form.field>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Save changes') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Change Password')" :subtitle="__('Choose a strong password you do not use anywhere else.')">
            <form method="POST" action="{{ route('admin.profile.password') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <x-form.field id="current_password" name="current_password" :label="__('Current password')" required
                              hint="{{ __('Confirm your identity.') }}"
                              help="{{ __('Enter the password you currently sign in with. It is only used to verify this change and is never stored or echoed.') }}">
                    <x-ui.input id="current_password" name="current_password" type="password" autocomplete="current-password"
                           required aria-describedby="current_password-hint current_password-error"
                           :invalid="$errors->has('current_password')" />
                </x-form.field>

                <x-form.field id="password" name="password" :label="__('New password')" required
                              hint="{{ __('At least :n characters.', ['n' => $minPassword]) }}"
                              help="{{ __('Must be at least the configured minimum length, must be confirmed, and must differ from your current password.') }}">
                    <x-ui.input id="password" name="password" type="password" autocomplete="new-password"
                           required minlength="{{ $minPassword }}"
                           aria-describedby="password-hint password-error" :invalid="$errors->has('password')" />
                </x-form.field>

                <x-form.field id="password_confirmation" name="password_confirmation" :label="__('Confirm new password')" required
                              hint="{{ __('Repeat the new password.') }}"
                              help="{{ __('Must match the new password exactly.') }}">
                    <x-ui.input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                           required aria-describedby="password_confirmation-hint password_confirmation-error"
                           :invalid="$errors->has('password_confirmation')" />
                </x-form.field>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Change password') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-admin-layout>
