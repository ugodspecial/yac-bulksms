<div>
    <x-ui.page-header
        title="Suppression list"
        subtitle="Numbers on this list are never messaged, even if a contact remains subscribed. Unsubscribes are added here automatically."
    >
        <x-slot:actions>
            <x-ui.button wire:click="openForm" size="sm">
                <x-ui.icon name="plus" class="h-4 w-4" /> Add number
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.stat label="Suppressed numbers" :value="number_format($total)" hint="Excluded from every campaign" icon="shield" tone="rose" />

        <x-ui.card class="p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold text-slate-900">Why numbers land here</h2>
            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> A contact replies with an opt-out keyword (for example <code class="rounded bg-slate-100 px-1">STOP</code>) — requires the inbound webhook.</li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> An operator unsubscribes the contact from the contacts screen.</li>
                <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Someone adds the number manually below (complaints, regulator requests, internal blocklists).</li>
            </ul>
            <p class="mt-3 text-xs text-slate-500">Removing a number from this list does not re-subscribe the contact; update the contact's subscription status separately.</p>
        </x-ui.card>
    </div>

    <x-ui.card class="mt-4">
        <div class="border-b border-slate-200 p-4">
            <div class="max-w-sm">
                <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by phone or reason" />
            </div>
        </div>

        @if ($entries->isEmpty())
            <x-ui.empty-state
                title="{{ $search !== '' ? 'No suppressed numbers match your search' : 'Nothing is suppressed yet' }}"
                description="Numbers appear here when contacts unsubscribe or when an operator blocks them."
                icon="shield"
            />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Reason</th>
                            <th class="px-4 py-2">Source</th>
                            <th class="px-4 py-2">Added by</th>
                            <th class="px-4 py-2">Added</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($entries as $entry)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell font-mono text-xs">{{ format_phone($entry->phone) }}</td>
                                <td class="table-cell">{{ $entry->reason ?: '—' }}</td>
                                <td class="table-cell">
                                    <x-ui.badge class="bg-slate-100 text-slate-600 ring-slate-200">{{ ucfirst(str_replace('_', ' ', $entry->source)) }}</x-ui.badge>
                                </td>
                                <td class="table-cell text-sm text-slate-600">{{ $entry->creator?->name ?? 'System' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $entry->created_at->timezone(settings()->timezone())->toDayDateTimeString() }}</td>
                                <td class="table-cell text-right">
                                    <button type="button" wire:click="confirmRemove({{ $entry->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $entries->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showForm">
        <h2 class="text-lg font-semibold text-slate-900">Suppress a number</h2>
        <p class="mt-1 text-sm text-slate-500">The number is normalised to E.164 and permanently excluded from campaign audiences.</p>

        <div class="mt-4 space-y-4">
            <x-ui.field label="Phone number" name="phone" hint="Local or international format, for example 0803 123 4567 or +2348031234567.">
                <x-ui.input wire:model="phone" placeholder="08031234567" />
            </x-ui.field>

            <x-ui.field label="Reason" name="reason">
                <x-ui.input wire:model="reason" placeholder="Customer complaint" />
            </x-ui.field>
        </div>

        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            <x-ui.button type="button" wire:click="add" wire:loading.attr="disabled" wire:target="add">
                <span wire:loading.remove wire:target="add">Suppress number</span>
                <span wire:loading wire:target="add">Saving…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal show="showRemove">
        <h2 class="text-lg font-semibold text-slate-900">Remove from suppression list?</h2>
        <p class="mt-1 text-sm text-slate-500">
            The number will be eligible for future campaigns again if the contact is subscribed. Only do this when you are certain the
            recipient wants to receive messages.
        </p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showRemove', false)">Keep suppressed</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="remove" wire:loading.attr="disabled" wire:target="remove">
                <span wire:loading.remove wire:target="remove">Remove from list</span>
                <span wire:loading wire:target="remove">Removing…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
