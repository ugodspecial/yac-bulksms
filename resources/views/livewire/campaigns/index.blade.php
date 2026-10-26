<div>
    <x-ui.page-header title="SMS campaigns" subtitle="Drafts, scheduled sends and completed campaigns.">
        <x-slot:actions>
            <x-ui.button href="{{ route('campaigns.create') }}">
                <x-ui.icon name="plus" class="h-4 w-4" /> Create campaign
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_auto]">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                <x-ui.icon name="search" class="h-4 w-4" />
            </span>
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search campaign name or message" class="pl-9" />
        </div>
        <x-ui.select wire:model.live="status">
            <option value="">All statuses</option>
            @foreach ($statuses as $campaignStatus)
                <option value="{{ $campaignStatus->value }}">{{ $campaignStatus->label() }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <x-ui.card>
        @if ($campaigns->isEmpty())
            <x-ui.empty-state title="No campaigns found" description="Create a campaign to send SMS to your contacts or contact groups." icon="megaphone">
                <x-slot:actions>
                    <x-ui.button href="{{ route('campaigns.create') }}" size="sm">Create campaign</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Campaign</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2 text-right">Recipients</th>
                            <th class="px-4 py-2 text-right">Delivered</th>
                            <th class="px-4 py-2 text-right">Accepted</th>
                            <th class="px-4 py-2 text-right">Failed</th>
                            <th class="px-4 py-2">Created</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($campaigns as $campaign)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $campaign->name }}</a>
                                    <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($campaign->message, 60) }}</p>
                                </td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-ui.badge>
                                    @if ($campaign->scheduled_at && $campaign->status === App\Enums\CampaignStatus::Scheduled)
                                        <p class="mt-1 text-[11px] text-slate-500">{{ $campaign->scheduled_at->timezone(settings()->timezone())->toDayDateTimeString() }}</p>
                                    @endif
                                </td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->total_recipients) }}</td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->delivered_count) }}</td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->accepted_count) }}</td>
                                <td class="table-cell text-right tabular-nums {{ $campaign->failed_count > 0 ? 'text-rose-600' : '' }}">{{ number_format($campaign->failed_count) }}</td>
                                <td class="table-cell text-xs text-slate-500">
                                    {{ $campaign->creator?->name ?? 'System' }}<br>
                                    {{ $campaign->created_at?->diffForHumans() }}
                                </td>
                                <td class="table-cell">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('campaigns.show', $campaign) }}" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" title="Open campaign">
                                            <x-ui.icon name="chart" class="h-4 w-4" />
                                        </a>
                                        @can('update', $campaign)
                                            <a href="{{ route('campaigns.edit', $campaign) }}" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" title="Edit draft">
                                                <x-ui.icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('delete', $campaign)
                                            <button type="button" wire:click="confirmDelete({{ $campaign->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Delete draft">
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
            <div class="border-t border-slate-200 px-4 py-3">{{ $campaigns->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal show="showDelete">
        <h2 class="text-lg font-semibold text-slate-900">Delete draft campaign?</h2>
        <p class="mt-1 text-sm text-slate-500">Only drafts that have never been sent can be deleted. This cannot be undone.</p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="delete">
                <span wire:loading.remove wire:target="delete">Delete draft</span>
                <span wire:loading wire:target="delete">Deleting…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
