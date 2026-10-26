<div>
    <x-ui.page-header title="My profile" subtitle="Manage the credentials for your account." />

    @if ($user->must_change_password)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Your password was set by an administrator. Please choose a new password now.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card class="p-5">
            <h2 class="text-sm font-semibold text-slate-900">Account</h2>
            <dl class="mt-3 divide-y divide-slate-100">
                <x-ui.details label="Name" :value="$user->name" />
                <x-ui.details label="Email" :value="$user->email" />
                <x-ui.details label="Role" :value="$user->role->label()" />
                <x-ui.details label="Last sign-in" :value="$user->last_login_at?->toDayDateTimeString() ?? 'This is your first sign-in'" />
            </dl>
            <p class="mt-4 text-xs text-slate-500">
                Changing your name, email or role is done by an administrator in User Management.
            </p>
        </x-ui.card>

        <x-ui.card class="p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold text-slate-900">Change password</h2>
            <form wire:submit="updatePassword" class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.field label="Current password" name="current_password">
                        <x-ui.input type="password" wire:model="current_password" autocomplete="current-password" required />
                    </x-ui.field>
                </div>
                <x-ui.field label="New password" name="password" hint="At least 10 characters with letters and numbers.">
                    <x-ui.input type="password" wire:model="password" autocomplete="new-password" required />
                </x-ui.field>
                <x-ui.field label="Confirm new password" name="password_confirmation">
                    <x-ui.input type="password" wire:model="password_confirmation" autocomplete="new-password" required />
                </x-ui.field>
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">
                        <span wire:loading.remove wire:target="updatePassword">Update password</span>
                        <span wire:loading wire:target="updatePassword">Saving…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
