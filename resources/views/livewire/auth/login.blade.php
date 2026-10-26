<div>
    <h2 class="text-xl font-semibold tracking-tight text-slate-900">Sign in</h2>
    <p class="mt-1 text-sm text-slate-500">Use the account your administrator created for you.</p>

    @if (session('error'))
        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    @if (session('status'))
        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="login" class="mt-6 space-y-4">
        <x-ui.field label="Email address" name="email">
            <x-ui.input id="email" type="email" wire:model="email" autocomplete="username" autofocus required placeholder="you@example.com" />
        </x-ui.field>

        <x-ui.field label="Password" name="password">
            <x-ui.input id="password" type="password" wire:model="password" autocomplete="current-password" required />
        </x-ui.field>

        <x-ui.checkbox wire:model="remember" label="Remember this device" />

        <x-ui.button type="submit" class="w-full" size="lg">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </x-ui.button>
    </form>

    <p class="mt-6 text-xs text-slate-500">
        Access is restricted to authorised SMS operators. All sign-in attempts are recorded in the audit log.
    </p>
</div>
