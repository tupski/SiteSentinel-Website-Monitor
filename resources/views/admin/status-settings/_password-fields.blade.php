{{-- Shared password fields with the eye toggle (ADR-034). --}}
<x-form.field name="password" :label="__('Password')"
              hint="{{ __('Only used in Password Protected mode.') }}"
              help="{{ __('Leave blank to keep the current password. Entering a new one rotates it and revokes every existing unlock for this page.') }}">
    <input id="password" name="password" type="password" autocomplete="new-password"
           aria-describedby="password-hint" class="block w-full rounded border border-slate-300 px-3 py-2 pr-10 text-sm">
</x-form.field>

<x-form.field name="password_confirmation" :label="__('Confirm password')"
              hint="{{ __('Repeat the password exactly.') }}"
              help="{{ __('Must match the password above.') }}">
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
           aria-describedby="password_confirmation-hint" class="block w-full rounded border border-slate-300 px-3 py-2 pr-10 text-sm">
</x-form.field>

<x-form.field name="clear_password" :label="__('Clear password')"
              hint="{{ __('Remove the stored password hash.') }}"
              help="{{ __('Removes the password so no unlock is possible until a new one is set. Do not clear while in Password Protected mode.') }}">
    <label class="flex items-center gap-2">
        <input type="hidden" name="clear_password" value="0">
        <input type="checkbox" name="clear_password" value="1" @checked((bool) old('clear_password', false))>
        <span class="text-sm">{{ __('Clear the current password') }}</span>
    </label>
</x-form.field>
