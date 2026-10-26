<div>
    <x-ui.page-header
        title="Delivery reports"
        subtitle="Every message attempt across campaigns, including provider acceptance and Termii delivery reports."
    >
        <x-slot:actions>
            @can('import-contacts')
                <x-ui.button href="{{ route('imports.index') }}" variant="secondary" size="sm">
                    <x-ui.icon name="upload" class="h-4 w-4" /> Import contacts
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($deliveryReporting)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">Delivery reports are not configured</p>
            <p class="mt-1">
                Termii delivery reports are pushed to this application's webhook. Until the webhook is enabled, messages stay marked as
                <span class="font-medium">accepted</span> and are never shown as delivered. Ask an administrator to configure the webhook in
                Settings, then register the URL in the Termii dashboard.
            </p>
        </div>
    @endunless

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6">
        <x-ui.stat label="Submitted" :value="number_format($summary['submitted'])" hint="Handed to Termii" icon="send" tone="indigo" />
        <x-ui.stat label="Accepted" :value="number_format($summary['accepted'])" hint="No delivery report yet" icon="clock" tone="sky" />
        <x-ui.stat label="Delivered" :value="number_format($summary['delivered'])" hint="Confirmed by report" icon="check" tone="emerald" />
        <x-ui.stat label="Failed" :value="number_format($summary['failed'])" hint="Provider rejection" icon="alert" tone="rose" />
        <x-ui.stat label="Pending" :value="number_format($summary['pending'])" hint="Not yet submitted" icon="info" tone="amber" />
        <x-ui.stat label="Unknown" :value="number_format($summary['unknown'])" hint="Outcome unclear" icon="shield" tone="slate" />
    </div>

    <x-ui.card class="mt-4">
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <x-ui.select wire:model.live="campaign">
                <option value="">All campaigns</option>
                @foreach ($campaigns as $campaignOption)
                    <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $recipientStatus)
                    <option value="{{ $recipientStatus->value }}">{{ $recipientStatus->label() }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input type="search" wire:model.live.debounce.300ms="phone" placeholder="Phone number" />

            <x-ui.field label="From" name="from">
                <x-ui.input type="date" wire:model.live="from" />
            </x-ui.field>

            <x-ui.field label="To" name="to">
                <x-ui.input type="date" wire:model.live="to" />
            </x-ui.field>

            <div class="flex items-end">
                <x-ui.button type="button" variant="secondary" size="sm" class="w-full" wire:click="$set('campaign', ''); $set('status', ''); $set('phone', ''); $set('from', ''); $set('to', '')">
                    Reset filters
                </x-ui.button>
            </div>
        </div>

        @if ($recipients->isEmpty())
            <x-ui.empty-state
                title="No message attempts match these filters"
                description="Delivery records appear here once a campaign has been confirmed and handed to Termii."
                icon="chart"
            />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Campaign</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Provider status</th>
                            <th class="px-4 py-2">Submitted</th>
                            <th class="px-4 py-2">Delivered</th>
                            <th class="px-4 py-2">Last checked</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recipients as $recipient)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell font-mono text-xs">{{ format_phone($recipient->phone) }}</td>
                                <td class="table-cell">
                                    @if ($recipient->campaign)
                                        <a href="{{ route('campaigns.show', $recipient->campaign) }}" class="font-medium text-indigo-600 hover:text-indigo-500">
                                            {{ $recipient->campaign->name }}
                                        </a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$recipient->status->badgeClasses()">{{ $recipient->status->label() }}</x-ui.badge>
                                    @if ($recipient->ambiguous)
                                        <p class="mt-1 text-[11px] text-amber-700">Awaiting reconciliation</p>
                                    @endif
                                </td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->provider_status ?: '—' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->submitted_at?->timezone(settings()->timezone())->toDateTimeString() ?? '—' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->delivered_at?->timezone(settings()->timezone())->toDateTimeString() ?? '—' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->last_reconciled_at?->timezone(settings()->timezone())->toDateTimeString() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $recipients->links() }}</div>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-slate-500">
        "Accepted" means Termii acknowledged the request. "Delivered" is only shown when Termii explicitly reports delivery —
        absence of a report is never treated as zero deliveries.
    </p>
</div>
