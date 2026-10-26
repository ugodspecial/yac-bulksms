<div>
    <x-ui.page-header title="Contact groups" subtitle="Reusable audiences for campaigns. Eligible counts exclude unsubscribed and suppressed numbers.">
        <x-slot:actions>
            <x-ui.button href="{{ route('imports.index') }}" variant="secondary" size="sm">
                <x-ui.icon name="upload" class="h-4 w-4" /> Import contacts
            </x-ui.button>
            @can('create', App\Models\ContactGroup::class)
                <x-ui.button wire:click="openCreate" size="sm">
                    <x-ui.icon name="plus" class="h-4 w-4" /> New group
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        @if ($groups->isEmpty())
            <x-ui.empty-state title="No contact groups yet" description="Group contacts by audience — for example customers, staff or a single region." icon="collection">
                <x-slot:actions>
                    @can('create', App\Models\ContactGroup::class)
                        <x-ui.button wire:click="openCreate" size="sm">Create a group</x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Group</th>
                            <th class="px-4 py-2 text-right">Contacts</th>
                            <th class="px-4 py-2 text-right">Eligible</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($groups as $groupItem)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <a href="{{ route('groups.show', $groupItem) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $groupItem->name }}</a>
                                    @if ($groupItem->description)
                                        <p class="text-xs text-slate-500">{{ $groupItem->description }}</p>
                                    @endif
                                </td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($groupItem->contacts_count) }}</td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($groupItem->subscribed_count) }}</td>
                                <td class="table-cell">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('groups.show', $groupItem) }}" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" title="Open group">
                                            <x-ui.icon name="users" class="h-4 w-4" />
                                        </a>
                                        @can('update', $groupItem)
                                            <button type="button" wire:click="openEdit({{ $groupItem->id }})" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" title="Rename group">
                                                <x-ui.icon name="pencil" class="h-4 w-4" />
                                            </button>
                                        @endcan
                                        @can('delete', $groupItem)
                                            <button type="button" wire:click="confirmDelete({{ $groupItem->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Delete group">
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
            <div class="border-t border-slate-200 px-4 py-3">{{ $groups->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showForm">
        <form wire:submit="save">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Rename group' : 'New contact group' }}</h2>
            <div class="mt-4 space-y-4">
                <x-ui.field label="Group name" name="name">
                    <x-ui.input wire:model="name" placeholder="Lagos customers" />
                </x-ui.field>
                <x-ui.field label="Description (optional)" name="description">
                    <x-ui.input wire:model="description" placeholder="Customers in Lagos state" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex items-center justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit">
                    <span wire:loading.remove wire:target="save">Save group</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal show="showDelete">
        <h2 class="text-lg font-semibold text-slate-900">Delete group?</h2>
        <p class="mt-1 text-sm text-slate-500">The group is deleted but the contacts themselves are kept. Campaigns already created are not affected.</p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete">
                <span wire:loading.remove wire:target="delete">Delete group</span>
                <span wire:loading wire:target="delete">Deleting…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
