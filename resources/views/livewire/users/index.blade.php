<div>
    <x-ui.page-header
        title="User management"
        subtitle="Create operator accounts and control which actions they can perform. Administrators always hold every permission."
    >
        <x-slot:actions>
            <x-ui.button wire:click="openCreate" size="sm">
                <x-ui.icon name="plus" class="h-4 w-4" /> New user
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        @if ($users->isEmpty())
            <x-ui.empty-state title="No users yet" description="Create the first operator account." icon="users" />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Role</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Permissions</th>
                            <th class="px-4 py-2">Last login</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($users as $user)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <p class="font-medium text-slate-900">{{ $user->name }}</p>
                                    @if ($user->is(auth()->user()))
                                        <p class="text-xs text-indigo-600">You</p>
                                    @endif
                                </td>
                                <td class="table-cell text-sm text-slate-600">{{ $user->email }}</td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$user->role === App\Enums\UserRole::Admin ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">
                                        {{ $user->role->label() }}
                                    </x-ui.badge>
                                </td>
                                <td class="table-cell">
                                    @if (! $user->is_active)
                                        <x-ui.badge class="bg-rose-50 text-rose-700 ring-rose-200">Disabled</x-ui.badge>
                                    @elseif ($user->must_change_password)
                                        <x-ui.badge class="bg-amber-50 text-amber-700 ring-amber-200">Must change password</x-ui.badge>
                                    @else
                                        <x-ui.badge class="bg-emerald-50 text-emerald-700 ring-emerald-200">Active</x-ui.badge>
                                    @endif
                                </td>
                                <td class="table-cell text-xs text-slate-600">
                                    @if ($user->isAdmin())
                                        <span class="text-slate-500">All permissions</span>
                                    @elseif (empty($user->permissions))
                                        <span class="text-slate-400">None</span>
                                    @else
                                        {{ count($user->permissions) }} assigned
                                    @endif
                                </td>
                                <td class="table-cell text-xs text-slate-500">{{ $user->last_login_at?->timezone(settings()->timezone())->toDayDateTimeString() ?? 'Never' }}</td>
                                <td class="table-cell text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" wire:click="openEdit({{ $user->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50">Edit</button>
                                        @can('delete', $user)
                                            <button type="button" wire:click="confirmDelete({{ $user->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showForm" max-width="max-w-2xl">
        <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit user' : 'New user' }}</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ $editingId ? 'Leave the password blank to keep the current one.' : 'The user is asked to change this password at first sign-in.' }}
        </p>

        <form wire:submit="save" class="mt-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Full name" name="name">
                    <x-ui.input wire:model="name" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="Email address" name="email">
                    <x-ui.input type="email" wire:model="email" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="Role" name="role">
                    <x-ui.select wire:model.live="role">
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                :label="$editingId ? 'New password (optional)' : 'Password'" name="password" hint="At least 10 characters with letters and numbers.">
                    <x-ui.input type="password" wire:model="password" autocomplete="new-password" />
                </x-ui.field>
            </div>

            <div class="flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 p-4">
                <x-ui.checkbox wire:model="isActive" label="Account is active" />
                <x-ui.checkbox wire:model="mustChangePassword" label="Must change password at next sign-in" />
            </div>

            <div @class([
                'rounded-lg border border-slate-200 p-4',
                'opacity-60' => $role === 'admin',
            ])>
                <p class="text-sm font-medium text-slate-900">Permissions</p>
                @if ($role === 'admin')
                    <p class="mt-1 text-xs text-slate-500">Administrators implicitly hold every permission, including settings and user management.</p>
                @else
                    <p class="mt-1 text-xs text-slate-500">Choose the actions this operator may perform.</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($permissionOptions as $permission)
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    value="{{ $permission->value }}"
                                    wire:model="permissions"
                                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span>
                                    <span class="block font-medium">{{ $permission->label() }}</span>
                                    <span class="block text-xs text-slate-500">{{ $permission->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('permissions') <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                @endif
            </div>

            @if ($errors->any() && ! $errors->has('name') && ! $errors->has('email') && ! $errors->has('password') && ! $errors->has('role'))
                <div class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $errors->first() }}</div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save user</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal show="showDelete">
        <h2 class="text-lg font-semibold text-slate-900">Delete this user?</h2>
        <p class="mt-1 text-sm text-slate-500">The account is removed immediately. Campaigns and audit entries they created are kept for the record.</p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Delete user</x-ui.button>
        </div>
    </x-ui.modal>
</div>
