<div>
    <x-ui.page-header title="Dashboard" subtitle="A live view of your contacts, campaigns and Termii delivery results.">
        <x-slot:actions>
            <x-ui.button href="{{ route('campaigns.create') }}" variant="primary">
                <x-ui.icon name="plus" class="h-4 w-4" /> New campaign
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-ui.stat label="Contacts" :value="number_format($totals['contacts'])" :hint="number_format($totals['subscribed']).' subscribed'" icon="users" tone="indigo" />
        <x-ui.stat label="Contact groups" :value="number_format($totals['groups'])" hint="Reusable audiences" icon="collection" tone="sky" />
        <x-ui.stat label="Campaigns" :value="number_format($totals['campaigns'])" :hint="$activeCampaigns.' in progress or scheduled'" icon="megaphone" tone="slate" />
        <x-ui.stat label="Messages submitted" :value="number_format($totals['submitted'])" hint="Accepted by Termii" icon="send" tone="indigo" />
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-ui.stat
            label="Delivered"
            :value="number_format($totals['delivered'])"
            :hint="$deliveryReporting ? 'Confirmed by provider delivery reports' : 'No delivery reports received yet'"
            icon="check"
            tone="emerald"
        />
        <x-ui.stat label="Failed" :value="number_format($totals['failed'])" hint="Rejected or failed at delivery" icon="alert" tone="rose" />
        <x-ui.stat label="Pending / processing" :value="number_format($totals['in_flight'])" :hint="number_format($totals['accepted']).' awaiting delivery report'" icon="clock" tone="amber" />
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Termii balance</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900">
                        @if ($provider->balance !== null)
                            {{ number_format((float) $provider->balance, 2) }}
                            <span class="text-sm font-medium text-slate-500">{{ $provider->currency ?? 'NGN' }}</span>
                        @else
                            <span class="text-base font-medium text-slate-500">Not checked yet</span>
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        @if ($provider->last_balance_at)
                            Checked {{ $provider->last_balance_at->diffForHumans() }}
                        @else
                            Check it in Settings
                        @endif
                    </p>
                </div>
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="wallet" class="h-5 w-5" />
                </span>
            </div>
        </div>
    </div>

    @if ($provider->last_error)
        <div class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <x-ui.icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-medium">Last Termii error ({{ $provider->last_error_at?->diffForHumans() ?? 'unknown time' }})</p>
                <p class="mt-0.5 text-amber-800">{{ $provider->last_error }}</p>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Campaign activity (last 14 days)</h2>
                <div class="flex items-center gap-3 text-xs text-slate-500">
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-indigo-500"></span> Campaigns</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-slate-300"></span> Messages submitted</span>
                </div>
            </div>
            <div class="px-4 py-5">
                @php $max = max(1, $activity->max(fn ($day) => max($day['campaigns'], $day['messages']))); @endphp
                <div class="flex h-40 items-end gap-1.5 sm:gap-2.5">
                    @foreach ($activity as $day)
                        <div class="group flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $day['label'] }} — {{ $day['campaigns'] }} campaign(s), {{ $day['messages'] }} message(s)">
                            <div class="flex h-full w-full items-end justify-center gap-0.5">
                                <div class="w-1/3 rounded-t bg-indigo-500/80" style="height: {{ max(2, (int) round($day['campaigns'] / $max * 100)) }}%"></div>
                                <div class="w-1/3 rounded-t bg-slate-300" style="height: {{ max(2, (int) round($day['messages'] / $max * 100)) }}%"></div>
                            </div>
                            <span class="hidden text-[10px] text-slate-400 sm:block">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d') }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">Bars show the relative volume per day. Delivery figures come from Termii reports, not estimates.</p>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Recent delivery failures</h2>
            </div>
            @if ($recentFailures->isEmpty())
                <x-ui.empty-state title="No failures recorded" description="Failures reported by Termii will appear here." icon="check" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentFailures as $failure)
                        <li class="px-4 py-3">
                            <p class="text-sm font-medium text-slate-900">{{ format_phone($failure->phone) }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $failure->campaign?->name ?? 'Campaign removed' }}
                                @if ($failure->failed_at) · {{ $failure->failed_at->diffForHumans() }} @endif
                            </p>
                            <p class="mt-1 text-xs text-rose-600">{{ $failure->error_message ?: 'No failure reason provided.' }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card class="mt-4">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Recent campaigns</h2>
            <a href="{{ route('campaigns.index') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">View all</a>
        </div>
        @if ($recentCampaigns->isEmpty())
            <x-ui.empty-state title="No campaigns yet" description="Create your first SMS campaign to see it here." icon="megaphone">
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
                            <th class="px-4 py-2 text-right">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recentCampaigns as $campaign)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell">
                                    <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $campaign->name }}</a>
                                </td>
                                <td class="table-cell"><x-ui.badge :class="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-ui.badge></td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->total_recipients) }}</td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->delivered_count) }}</td>
                                <td class="table-cell text-right tabular-nums">{{ number_format($campaign->accepted_count) }}</td>
                                <td class="table-cell text-right tabular-nums {{ $campaign->failed_count > 0 ? 'text-rose-600' : '' }}">{{ number_format($campaign->failed_count) }}</td>
                                <td class="table-cell text-right text-xs text-slate-500">{{ $campaign->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
        <p>
            <span class="font-medium text-slate-700">How delivery data works:</span>
            Termii acceptance means the message was queued with the provider. "Delivered" only appears when a delivery report
            confirms handset delivery; messages without a report stay in "Accepted by provider" or "Unknown".
            @if (! $deliveryReporting)
                Delivery reports are not available yet — configure the Termii webhook or wait for the reconciliation job.
            @endif
        </p>
    </div>
</div>
