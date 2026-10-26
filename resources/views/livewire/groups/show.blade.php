<div>
    <x-ui.page-header :title="$group->name" subtitle="Manage the contacts in this group.">
        <x-slot:actions>
            <x-ui.button href="{{ route('imports.index', ['group' => $group->id]) }}" variant="secondary" size="sm">
                <x-ui.icon name="upload" class="h-4 w-4" /> Import into group
            </x-ui.button>
            <x-ui.button href="{{ route('groups.index') }}" variant="secondary" size="sm">All groups</x-ui.button>
            @can('update', $group)
                <x-ui.button wire:click="openAdd" size="sm">
                    <x-ui.icon name="plus" class="h-4 w-4" /> Add contacts
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-ui.stat label="Contacts in group" :value="number_format($totalCount)" tone="indigo" icon="users" />
        <x-ui.stat label="Eligible recipients" :value="number_format($subscribedCount)" hint="Subscribed and not suppressed" tone="emerald" icon="check" />
        <div class="col-span-2 flex items-center justify-end gap-2">
            @can('update', $group)
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="removeSelected" wire:confirm="Remove the selected contacts from this group?">
                    Remove selected ({{ count($selected) }})
                </x-ui.button>
            @endcan
        </div>
    </div>

    <x-ui.card class="mt-4">
        <div class="border-b border-slate-200 p-4">
            <div class="relative max-w-md">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                    <x-ui.icon name="search" class="h-4 w-4" />
                </span>
                <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search contacts in this group" class="pl-9" />
            </div>
            @error('selected')
                <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        @if ($contacts->isEmpty())
            <x-ui.empty-state title="This group has no contacts" description="Add existing contacts or import a new list straight into this group." icon="collection" />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="w-10 px-4 py-2"></th>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($contacts as $contact)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <input type="checkbox" value="{{ $contact->id }}" wire:model.live="selected" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </td>
                                <td class="table-cell">
                                    <a href="{{ route('contacts.show', $contact) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $contact->displayName() }}</a>
                                </td>
                                <td class="table-cell font-mono text-xs">{{ format_phone($contact->phone) }}</td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$contact->subscription_status->badgeClasses()">{{ $contact->subscription_status->label() }}</x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $contacts->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showAdd" maxWidth="max-w-2xl">
        <h2 class="text-lg font-semibold text-slate-900">Add contacts to {{ $group->name }}</h2>
        <div class="mt-4">
            <x-ui.input type="search" wire:model.live.debounce.300ms="addSearch" placeholder="Search by name or phone" />
        </div>
        @error('addSelected')
            <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
        @enderror
        <div class="mt-4 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
            @forelse ($candidates as $candidate)
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50">
                    <input type="checkbox" value="{{ $candidate->id }}" wire:model.live="addSelected" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="min-w-0">
                        <span class="block truncate text-sm text-slate-900">{{ $candidate->displayName() }}</span>
                        <span class="block text-xs text-slate-500">{{ format_phone($candidate->phone) }}</span>
                    </span>
                </label>
            @empty
                <p class="px-3 py-6 text-center text-sm text-slate-500">No matching contacts available.</p>
            @endforelse
        </div>
        <div class="mt-6 flex items-center justify-between">
            <span class="text-xs text-slate-500">{{ count($addSelected) }} selected</span>
            <div class="flex items-center gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showAdd', false)">Cancel</x-ui.button>
                <x-ui.button type="button" wire:click="addContacts">Add selected</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
