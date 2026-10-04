{{-- Shared password fields with the eye toggle (ADR-034). --}}
<x-form.field name="password" :label="__('Password')"
              hint="{{ __('Only used in Password Protected mode.') }}"
              help="{{ __('Leave blank to keep the current password. Entering a new one rotates it and revokes every existing unlock for this page.') }}">
    <x-ui.input id="password" name="password" type="password" autocomplete="new-password" class="pr-10"
           aria-describedby="password-hint" :invalid="$errors->has('password')" />
</x-form.field>

<x-form.field name="password_confirmation" :label="__('Confirm password')"
              hint="{{ __('Repeat the password exactly.') }}"
              help="{{ __('Must match the password above.') }}">
    <x-ui.input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="pr-10"
           aria-describedby="password_confirmation-hint" :invalid="$errors->has('password_confirmation')" />
</x-form.field>

<x-form.field name="clear_password" :label="__('Clear password')"
              hint="{{ __('Remove the stored password hash.') }}"
              help="{{ __('Removes the password so no unlock is possible until a new one is set. Do not clear while in Password Protected mode.') }}">
    <input type="hidden" name="clear_password" value="0">
    <x-ui.checkbox name="clear_password" value="1" :checked="(bool) old('clear_password', false)"
            :label="__('Clear the current password')" />
</x-form.field>
