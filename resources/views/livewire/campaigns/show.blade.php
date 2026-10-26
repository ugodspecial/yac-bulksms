<div>
    <x-ui.page-header :title="$campaign->name" :subtitle="'Created '.$campaign->created_at?->toDayDateTimeString().' by '.($campaign->creator?->name ?? 'System')">
        <x-slot:actions>
            @can('update', $campaign)
                <x-ui.button href="{{ route('campaigns.edit', $campaign) }}" variant="secondary" size="sm">
                    <x-ui.icon name="pencil" class="h-4 w-4" /> Edit draft
                </x-ui.button>
            @endcan
            @can('export', $campaign)
                <x-ui.button href="{{ route('campaigns.export', $campaign) }}" variant="secondary" size="sm">
                    <x-ui.icon name="download" class="h-4 w-4" /> Export results
                </x-ui.button>
            @endcan
            @can('confirm', $campaign)
                @if ($campaign->status->canBeConfirmed())
                    <x-ui.button wire:click="openConfirm" size="sm">
                        <x-ui.icon name="send" class="h-4 w-4" /> {{ $campaign->status === App\Enums\CampaignStatus::NeedsReview ? 'Authorise & send' : 'Confirm & send' }}
                    </x-ui.button>
                @endif
            @endcan
            @can('cancel', $campaign)
                @if ($campaign->status->canBeCancelled())
                    <x-ui.button wire:click="openCancel" variant="danger" size="sm">Cancel campaign</x-ui.button>
                @endif
            @endcan
            @if ($canRetry && $retryableFailures > 0)
                <x-ui.button wire:click="$set('showRetry', true)" variant="secondary" size="sm">
                    <x-ui.icon name="refresh" class="h-4 w-4" /> Retry failed ({{ $retryableFailures }})
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-ui.badge :class="$campaign->status->badgeClasses()">{{ $campaign->status->label() }}</x-ui.badge>
        <x-ui.badge class="bg-slate-100 text-slate-600 ring-slate-200">{{ $campaign->dispatch_mode->label() }}</x-ui.badge>
        <x-ui.badge class="bg-slate-100 text-slate-600 ring-slate-200">{{ $campaign->encoding === 'ucs-2' ? 'Unicode (UCS-2)' : 'GSM-7' }}</x-ui.badge>
        @if ($campaign->scheduled_at && $campaign->status === App\Enums\CampaignStatus::Scheduled)
            <x-ui.badge class="bg-sky-50 text-sky-700 ring-sky-200">
                Scheduled for {{ $campaign->scheduled_at->timezone(settings()->timezone())->toDayDateTimeString() }}
            </x-ui.badge>
        @endif
    </div>

    @if ($campaign->failure_reason)
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            <p class="font-medium">Campaign issue</p>
            <p class="mt-1">{{ $campaign->failure_reason }}</p>
        </div>
    @endif

    @if (($counts['unknown'] ?? 0) > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">{{ $counts['unknown'] }} message(s) have an unknown outcome</p>
            <p class="mt-1">These were never retried automatically to avoid duplicate SMS. Verify each one in Termii (or use "Check provider status" where a message ID exists) before resending.</p>
        </div>
    @endif

    @if ($campaign->status === App\Enums\CampaignStatus::Completed || $campaign->status === App\Enums\CampaignStatus::CompletedWithErrors || $campaign->status === App\Enums\CampaignStatus::Failed)
        <div class="mb-4 h-2 overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full bg-indigo-500" style="width: {{ $campaign->progressPercent() }}%"></div>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-ui.stat label="Recipients" :value="number_format($campaign->total_recipients)" :hint="number_format($campaign->excluded_count).' excluded'" icon="users" tone="indigo" />
        <x-ui.stat label="Accepted by provider" :value="number_format($counts['accepted'])" hint="Waiting for a delivery report" icon="send" tone="sky" />
        <x-ui.stat label="Delivered" :value="number_format($counts['delivered'])" hint="Confirmed by Termii report" icon="check" tone="emerald" />
        <x-ui.stat label="Failed" :value="number_format($counts['failed'])" :hint="number_format($counts['unknown']).' unknown'" icon="alert" tone="rose" />
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <x-ui.card class="p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold text-slate-900">Message</h2>
            <p class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-800">{{ $campaign->message }}</p>
            <dl class="mt-3 divide-y divide-slate-100">
                <x-ui.details label="Sender ID" :value="$campaign->sender_id" />
                <x-ui.details label="Channel" :value="$campaign->channel === 'dnd' ? 'DND (transactional)' : 'Generic (promotional)'" />
                <x-ui.details label="Estimated SMS segments" :value="number_format($campaign->estimated_total_segments)" />
                <x-ui.details label="Pending / processing" :value="number_format($counts['pending'] + $counts['processing'])" />
                <x-ui.details label="Skipped" :value="number_format($counts['skipped'])" />
            </dl>
        </x-ui.card>

        <x-ui.card class="p-5">
            <h2 class="text-sm font-semibold text-slate-900">Campaign details</h2>
            <dl class="mt-3 divide-y divide-slate-100">
                <x-ui.details label="Created by" :value="$campaign->creator?->name ?? 'System'" />
                <x-ui.details label="Confirmed by" :value="$campaign->confirmer?->name ?? '—'" />
                <x-ui.details label="Confirmed at" :value="$campaign->confirmed_at?->timezone(settings()->timezone())->toDayDateTimeString() ?? '—'" />
                <x-ui.details label="Queued at" :value="$campaign->queued_at?->timezone(settings()->timezone())->toDayDateTimeString() ?? '—'" />
                <x-ui.details label="Completed at" :value="$campaign->completed_at?->timezone(settings()->timezone())->toDayDateTimeString() ?? '—'" />
                <x-ui.details label="Balance before send" :value="$campaign->balance_before !== null ? number_format((float) $campaign->balance_before, 2).' NGN' : '—'" />
                <x-ui.details label="Batch size" :value="$campaign->batch_size.' recipients per provider call'" />
            </dl>
            <p class="mt-3 text-xs text-slate-500">
                Delivery counts only include messages Termii explicitly reported as delivered.
            </p>
        </x-ui.card>
    </div>

    <x-ui.card class="mt-4">
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
            <x-ui.select wire:model.live="status">
                <option value="">All recipient statuses</option>
                @foreach ($statuses as $recipientStatus)
                    <option value="{{ $recipientStatus->value }}">{{ $recipientStatus->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="search" wire:model.live.debounce.300ms="phone" placeholder="Filter by phone number" />
            <div class="flex items-center justify-end gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="$set('status', ''); $set('phone', '')">Reset</x-ui.button>
            </div>
        </div>

        @error('reconcile')
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $message }}</div>
        @enderror
        @error('retry')
            <div class="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ $message }}</div>
        @enderror

        @if ($recipients->isEmpty())
            <x-ui.empty-state
                title="No recipients to show"
                description="Recipients are created when the campaign is confirmed. Adjust the filters if the campaign has already run."
                icon="users"
            />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Phone</th>
                            <th class="px-4 py-2">Contact</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Provider status</th>
                            <th class="px-4 py-2">Provider message ID</th>
                            <th class="px-4 py-2">Failure</th>
                            <th class="px-4 py-2">Submitted</th>
                            <th class="px-4 py-2">Delivered</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recipients as $recipient)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell font-mono text-xs">{{ format_phone($recipient->phone) }}</td>
                                <td class="table-cell">{{ $recipient->name ?: '—' }}</td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$recipient->status->badgeClasses()">{{ $recipient->status->label() }}</x-ui.badge>
                                    @if ($recipient->ambiguous)
                                        <p class="mt-1 text-[11px] text-amber-700">Needs reconciliation</p>
                                    @endif
                                </td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->provider_status ?: '—' }}</td>
                                <td class="table-cell font-mono text-[11px] text-slate-500">{{ $recipient->provider_message_id ?: '—' }}</td>
                                <td class="table-cell max-w-[220px] text-xs text-rose-600">{{ $recipient->error_message ?: '' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->submitted_at?->timezone(settings()->timezone())->toDateTimeString() ?? '—' }}</td>
                                <td class="table-cell text-xs text-slate-500">{{ $recipient->delivered_at?->timezone(settings()->timezone())->toDateTimeString() ?? '—' }}</td>
                                <td class="table-cell text-right">
                                    @can('retry', $campaign)
                                        @if ($recipient->provider_message_id)
                                            <button type="button" wire:click="reconcileRecipient({{ $recipient->id }})" class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50" title="Fetch the delivery report from Termii">
                                                Check status
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $recipients->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.card class="mt-4">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Campaign audit trail</h2>
        </div>
        @if ($audit->isEmpty())
            <x-ui.empty-state title="No activity recorded yet" description="Confirmations, cancellations and delivery updates appear here." icon="book" />
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($audit as $entry)
                    <li class="flex items-start justify-between gap-4 px-4 py-3">
                        <div>
                            <p class="text-sm text-slate-800">{{ $entry->description }}</p>
                            <p class="text-xs text-slate-500">{{ $entry->user?->name ?? 'System' }} · {{ $entry->created_at->timezone(settings()->timezone())->toDayDateTimeString() }}</p>
                        </div>
                        <span class="whitespace-nowrap rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{{ $entry->action }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.modal show="showConfirm">
        <h2 class="text-lg font-semibold text-slate-900">Send this campaign?</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ number_format($campaign->total_recipients) }} recipient(s) will receive
            <span class="font-medium text-slate-700">{{ $campaign->sender_id }}</span>:
        </p>
        <p class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-800">{{ $campaign->message }}</p>
        @if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture())
            <p class="mt-3 text-sm text-slate-600">It will be scheduled for {{ $campaign->scheduled_at->timezone(settings()->timezone())->toDayDateTimeString() }}.</p>
        @else
            <p class="mt-3 text-sm text-slate-600">It will be queued immediately after you confirm.</p>
        @endif
        @error('confirm')
            <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
        @enderror
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showConfirm', false)">Cancel</x-ui.button>
            <x-ui.button type="button" wire:click="confirmCampaign" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="confirmCampaign">Confirm &amp; send</span>
                <span wire:loading wire:target="confirmCampaign">Queuing…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal show="showCancel">
        <h2 class="text-lg font-semibold text-slate-900">Cancel campaign?</h2>
        <p class="mt-1 text-sm text-slate-500">Messages that have not been sent yet will be skipped. Messages already accepted by Termii cannot be recalled.</p>
        <div class="mt-4">
            <x-ui.field label="Reason (optional)" name="cancelReason">
                <x-ui.textarea rows="3" wire:model="cancelReason"></x-ui.textarea>
            </x-ui.field>
        </div>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showCancel', false)">Keep campaign</x-ui.button>
            <x-ui.button type="button" variant="danger" wire:click="cancel" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="cancel">Cancel campaign</span>
                <span wire:loading wire:target="cancel">Cancelling…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    <x-ui.modal show="showRetry">
        <h2 class="text-lg font-semibold text-slate-900">Retry failed messages?</h2>
        <p class="mt-1 text-sm text-slate-500">
            {{ $retryableFailures }} recipient(s) failed with an error that is safe to retry (rate limit, provider outage or network refusal).
            Ambiguous timeouts and unknown outcomes are never resent automatically.
        </p>
        <div class="mt-6 flex items-center justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showRetry', false)">Cancel</x-ui.button>
            <x-ui.button type="button" wire:click="retryFailed" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="retryFailed">Retry failed messages</span>
                <span wire:loading wire:target="retryFailed">Queuing…</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
