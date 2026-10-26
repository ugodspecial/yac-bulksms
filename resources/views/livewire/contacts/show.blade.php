<div>
    <x-ui.page-header :title="$contact->displayName()" subtitle="Contact details, consent history and recent messages.">
        <x-slot:actions>
            <x-ui.button href="{{ route('contacts.index') }}" variant="secondary" size="sm">Back to contacts</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card class="p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">Details</h2>
                <x-ui.badge :class="$contact->subscription_status->badgeClasses()">{{ $contact->subscription_status->label() }}</x-ui.badge>
            </div>
            <dl class="mt-3 divide-y divide-slate-100">
                <x-ui.details label="Phone" :value="format_phone($contact->phone)" />
                <x-ui.details label="Email" :value="$contact->email ?: '—'" />
                <x-ui.details label="Groups" :value="$groups->pluck('name')->implode(', ') ?: 'No groups'" />
                <x-ui.details label="Created" :value="$contact->created_at?->toDayDateTimeString()" />
                <x-ui.details label="Consent source" :value="$contact->consent_source ?: '—'" />
                @if ($contact->unsubscribed_at)
                    <x-ui.details label="Unsubscribed" :value="$contact->unsubscribed_at->toDayDateTimeString()" />
                    <x-ui.details label="Reason" :value="$contact->unsubscribe_reason ?: '—'" />
                @endif
            </dl>

            @if (! empty($contact->custom_fields))
                <div class="mt-4 rounded-lg bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Custom fields</p>
                    <dl class="mt-2 divide-y divide-slate-200">
                        @foreach ($contact->custom_fields as $key => $value)
                            <x-ui.details :label="ucfirst((string) $key)" :value="is_scalar($value) ? (string) $value : '—'" />
                        @endforeach
                    </dl>
                </div>
            @endif

            <div class="mt-5 border-t border-slate-200 pt-4">
                @if ($contact->isSubscribed())
                    @can('unsubscribe', $contact)
                        <form wire:submit="unsubscribe" class="space-y-3">
                            <x-ui.field label="Unsubscribe this contact" name="reason" hint="Adds the number to the suppression list.">
                                <x-ui.input wire:model="reason" placeholder="Reason for unsubscribing" />
                            </x-ui.field>
                            <x-ui.button type="submit" variant="danger" size="sm">Unsubscribe &amp; suppress</x-ui.button>
                        </form>
                    @endcan
                @else
                    @can('admin')
                        <x-ui.button wire:click="resubscribe" wire:confirm="Re-subscribe and remove the suppression entry?" size="sm" variant="success">
                            Re-subscribe contact
                        </x-ui.button>
                    @endcan
                @endif
            </div>
        </x-ui.card>

        <div class="space-y-4 lg:col-span-2">
            <div class="grid grid-cols-3 gap-3">
                <x-ui.stat label="Messages" :value="number_format($summary['messages'])" tone="indigo" icon="send" />
                <x-ui.stat label="Delivered" :value="number_format($summary['delivered'])" tone="emerald" icon="check" />
                <x-ui.stat label="Failed" :value="number_format($summary['failed'])" tone="rose" icon="alert" />
            </div>

            <x-ui.card>
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Recent campaign messages</h2>
                </div>
                @if ($recipients->isEmpty())
                    <x-ui.empty-state title="No messages yet" description="This contact has not been included in a campaign." icon="megaphone" />
                @else
                    <div class="overflow-x-auto">
                        <table class="table-base">
                            <thead class="table-head">
                                <tr>
                                    <th class="px-4 py-2">Campaign</th>
                                    <th class="px-4 py-2">Status</th>
                                    <th class="px-4 py-2">Provider status</th>
                                    <th class="px-4 py-2 text-right">Submitted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($recipients as $recipient)
                                    <tr>
                                        <td class="table-cell">
                                            @if ($recipient->campaign)
                                                <a href="{{ route('campaigns.show', $recipient->campaign) }}" class="text-indigo-600 hover:text-indigo-500">{{ $recipient->campaign->name }}</a>
                                            @else
                                                <span class="text-slate-400">Removed</span>
                                            @endif
                                        </td>
                                        <td class="table-cell"><x-ui.badge :class="$recipient->status->badgeClasses()">{{ $recipient->status->label() }}</x-ui.badge></td>
                                        <td class="table-cell text-xs text-slate-500">{{ $recipient->provider_status ?: '—' }}</td>
                                        <td class="table-cell text-right text-xs text-slate-500">{{ $recipient->submitted_at?->toDayDateTimeString() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Consent &amp; change history</h2>
                </div>
                @if ($audit->isEmpty())
                    <x-ui.empty-state title="No recorded changes" description="Contact changes and consent updates appear here." icon="book" />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($audit as $entry)
                            <li class="flex items-start justify-between gap-4 px-4 py-3">
                                <div>
                                    <p class="text-sm text-slate-800">{{ $entry->description }}</p>
                                    <p class="text-xs text-slate-500">{{ $entry->user?->name ?? 'System' }} · {{ $entry->created_at->toDayDateTimeString() }}</p>
                                </div>
                                <span class="whitespace-nowrap rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{{ $entry->action }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
