<div>
    <x-ui.page-header title="Contacts" subtitle="Search, edit and manage consent for everyone in your database.">
        <x-slot:actions>
            <x-ui.button href="{{ route('imports.index') }}" variant="secondary" size="sm">
                <x-ui.icon name="upload" class="h-4 w-4" /> Import
            </x-ui.button>
            @can('export', App\Models\Contact::class)
                <x-ui.button href="{{ route('contacts.export', request()->only('q', 'group', 'status')) }}" variant="secondary" size="sm">
                    <x-ui.icon name="download" class="h-4 w-4" /> Export CSV
                </x-ui.button>
            @endcan
            @can('create', App\Models\Contact::class)
                <x-ui.button wire:click="openCreate" size="sm">
                    <x-ui.icon name="plus" class="h-4 w-4" /> Add contact
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                    <x-ui.icon name="search" class="h-4 w-4" />
                </span>
                <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, phone or email" class="pl-9" />
            </div>
            <x-ui.select wire:model.live="group">
                <option value="">All groups</option>
                @foreach ($groups as $groupOption)
                    <option value="{{ $groupOption->id }}">{{ $groupOption->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="status">
                <option value="">All subscription states</option>
                <option value="subscribed">Subscribed</option>
                <option value="unsubscribed">Unsubscribed</option>
            </x-ui.select>
            <div class="flex items-center justify-between gap-2 text-xs text-slate-500 sm:justify-end">
                <span>{{ number_format($subscribedTotal) }} subscribed overall</span>
            </div>
        </div>

        @if ($contacts->isEmpty())
            <x-ui.empty-state
                title="No contacts found"
                description="Adjust the filters, add a contact manually, or import a CSV/Excel file."
                icon="users"
            >
                <x-slot:actions>
                    @can('create', App\Models\Contact::class)
                        <x-ui.button wire:click="openCreate" size="sm">Add your first contact</x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Groups</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($contacts as $contact)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <a href="{{ route('contacts.show', $contact) }}" class="font-medium text-slate-900 hover:text-indigo-600">
                                        {{ $contact->displayName() }}
                                    </a>
                                    @if ($contact->email)
                                        <p class="text-xs text-slate-500">{{ $contact->email }}</p>
                                    @endif
                                </td>
                                <td class="table-cell font-mono text-xs">{{ format_phone($contact->phone) }}</td>
                                <td class="table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($contact->groups as $groupItem)
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">{{ $groupItem->name }}</span>
                                        @empty
                                            <span class="text-xs text-slate-400">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$contact->subscription_status->badgeClasses()">{{ $contact->subscription_status->label() }}</x-ui.badge>
                                </td>
                                <td class="table-cell">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $contact)
                                            <button type="button" wire:click="openEdit({{ $contact->id }})" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700" title="Edit contact">
                                                <x-ui.icon name="pencil" class="h-4 w-4" />
                                            </button>
                                        @endcan
                                        @if ($contact->isSubscribed())
                                            @can('unsubscribe', $contact)
                                                <button type="button" wire:click="startUnsubscribe({{ $contact->id }})" class="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50" title="Unsubscribe and suppress">
                                                    <x-ui.icon name="shield" class="h-4 w-4" />
                                                </button>
                                            @endcan
                                        @else
                                            @can('admin')
                                                <button type="button" wire:click="resubscribe({{ $contact->id }})" wire:confirm="Re-subscribe this contact and remove the suppression entry?" class="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50" title="Re-subscribe">
                                                    <x-ui.icon name="check" class="h-4 w-4" />
                                                </button>
                                            @endcan
                                        @endif
                                        @can('delete', $contact)
                                            <button type="button" wire:click="confirmDelete({{ $contact->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Delete contact">
                                                <x-ui.icon name="trash" class="h-4 w-4" />
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $contacts->links() }}
            </div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showForm" maxWidth="max-w-2xl">
        <form wire:submit="save">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit contact' : 'Add contact' }}</h2>
            <p class="mt-1 text-sm text-slate-500">Numbers are normalised to international format and validated.</p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Full name" name="name">
                    <x-ui.input wire:model="name" placeholder="Ada Okafor" autocomplete="off" />
                </x-ui.field>
                <x-ui.field label="Phone number" name="phone" hint="Local or international format, e.g. 08031234567">
                    <x-ui.input wire:model="phone" placeholder="08031234567" autocomplete="off" />
                </x-ui.field>
                <div class="sm:col-span-2">
                    <x-ui.field label="Email (optional)" name="email">
                        <x-ui.input type="email" wire:model="email" placeholder="ada@example.com" autocomplete="off" />
                    </x-ui.field>
                </div>

                @foreach ($configuredCustomFields as $field)
                    <x-ui.field :label="'Custom field: '.$field" :name="'custom.'.$field">
                        <x-ui.input wire:model="custom.{{ $field }}" />
                    </x-ui.field>
                @endforeach
            </div>

            <div class="mt-6 flex items-center justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit">
                    <span wire:loading.remove wire:target="save">Save contact</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal show="showUnsubscribe">
        <h2 class="text-lg font-semibold text-slate-900">Unsubscribe contact</h2>
        <p class="mt-1 text-sm text-slate-500">The number is added to the suppression list and can never be included in a campaign until an administrator lifts it.</p>
        <div class="mt-4">
            <x-ui.field label="Reason" name="unsubscribeReason">
                <x-ui.textarea rows="3" wire:model="unsubscribeReason"></x-ui.textarea>
            </x-ui.field>
        </div>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showUnsubscribe', false)">Cancel</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="confirmUnsubscribe">
                <span wire:loading.remove wire:target="confirmUnsubscribe">Unsubscribe</span>
                <span wire:loading wire:target="confirmUnsubscribe">Working…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal show="showDelete">
        <h2 class="text-lg font-semibold text-slate-900">Delete contact?</h2>
        <p class="mt-1 text-sm text-slate-500">Deleting removes the contact from your database. Existing campaign history keeps a snapshot of the phone number. This cannot be undone.</p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete">
                <span wire:loading.remove wire:target="delete">Delete contact</span>
                <span wire:loading wire:target="delete">Deleting…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
