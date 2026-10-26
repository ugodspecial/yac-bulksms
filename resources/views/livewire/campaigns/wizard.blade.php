<div>
    <x-ui.page-header
        :title="$campaignId ? 'Edit campaign' : 'Create SMS campaign'"
        subtitle="Compose, choose recipients, then review and confirm. Nothing is sent until you confirm."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('campaigns.index') }}" variant="secondary" size="sm">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($campaign && $campaign->status === App\Enums\CampaignStatus::NeedsReview)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            This campaign was submitted for authorisation. An administrator must confirm it before it is sent.
        </div>
    @endif

    <ol class="mb-6 flex flex-wrap items-center gap-2 text-sm">
        @foreach ([1 => 'Compose', 2 => 'Recipients', 3 => 'Review & confirm'] as $number => $label)
            <li class="flex items-center gap-2">
                <span @class([
                    'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-indigo-600 text-white' => $step === $number,
                    'bg-emerald-100 text-emerald-700' => $step > $number,
                    'bg-slate-200 text-slate-500' => $step < $number,
                ])>{{ $step > $number ? '✓' : $number }}</span>
                <span @class(['font-medium text-slate-900' => $step === $number, 'text-slate-500' => $step !== $number])>{{ $label }}</span>
            </li>
            @if (! $loop->last)
                <li class="hidden h-px w-8 bg-slate-300 sm:block" aria-hidden="true"></li>
            @endif
        @endforeach
    </ol>

    @if ($step === 1)
        <div class="grid gap-4 lg:grid-cols-3">
            <x-ui.card class="p-5 lg:col-span-2">
                <div class="space-y-4">
                    <x-ui.field label="Campaign name" name="name">
                        <x-ui.input wire:model="name" placeholder="October customer update" />
                    </x-ui.field>

                    <x-ui.field label="Message" name="message" hint="Placeholders: @{{name}}, @{{phone}}, @{{email}} and approved custom fields.">
                        <x-ui.textarea rows="6" wire:model.live.debounce.400ms="message" placeholder="Hello @{{name}}, our October offer is live..."></x-ui.textarea>
                    </x-ui.field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Sender ID" name="sender_id">
                            @if ($approvedSenderIds === [])
                                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    No approved sender ID is configured. Ask an administrator to add one in Settings.
                                </p>
                            @else
                                <x-ui.select wire:model="sender_id">
                                    @foreach ($approvedSenderIds as $sender)
                                        <option value="{{ $sender }}">{{ $sender }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif
                        </x-ui.field>

                        <x-ui.field label="Channel" name="channel" hint="DND reaches numbers with Do-Not-Disturb enabled (requires activation on the Termii account).">
                            <x-ui.select wire:model="channel">
                                <option value="generic">Generic (promotional)</option>
                                <option value="dnd">DND (transactional)</option>
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="rounded-lg border border-slate-200 p-4">
                        <x-ui.checkbox wire:model.live="schedule" label="Schedule this campaign for later" />
                        @if ($schedule)
                            <div class="mt-3 max-w-xs">
                                <x-ui.field label="Send at" name="scheduled_at" :hint="'Times are in '.$currentTimezone">
                                    <x-ui.input type="datetime-local" wire:model="scheduled_at" />
                                </x-ui.field>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between border-t border-slate-200 pt-4">
                    <x-ui.button type="button" variant="secondary" wire:click="saveDraft" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                        <span wire:loading wire:target="saveDraft">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="nextStep" wire:loading.attr="disabled">
                        Continue to recipients
                    </x-ui.button>
                </div>
            </x-ui.card>

            <div class="space-y-4">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Message preview</h2>
                    <p class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-800">{{ $previewText ?: 'Your message preview appears here.' }}</p>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Encoding</dt>
                            <dd class="font-medium">{{ $previewSegments->encoding->label() }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Characters</dt>
                            <dd class="font-medium tabular-nums">{{ number_format($previewSegments->length) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">SMS segments per recipient</dt>
                            <dd class="font-medium tabular-nums">{{ $previewSegments->segments }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Configured limit</dt>
                            <dd class="font-medium tabular-nums">{{ $maxSegments }} segments</dd>
                        </div>
                    </dl>
                    @if ($previewSegments->segments > $maxSegments)
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            This message exceeds the configured segment limit and cannot be sent.
                        </p>
                    @endif
                    @if ($previewSegments->encoding === App\Enums\SmsEncoding::Ucs2)
                        <p class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900">
                            Unicode characters reduce the capacity to 70 characters per segment.
                        </p>
                    @endif
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Provider</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Termii balance</dt>
                            <dd class="font-medium">
                                @if ($providerStatus->balance !== null)
                                    {{ number_format((float) $providerStatus->balance, 2) }} {{ $providerStatus->currency ?? 'NGN' }}
                                @else
                                    Not checked
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">Dispatch mode</dt>
                            <dd class="font-medium">{{ $campaign?->dispatch_mode?->label() ?? 'Bulk (batched)' }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">Segment counts are estimates; Termii may bill differently for Unicode or rerouted messages.</p>
                </x-ui.card>
            </div>
        </div>
    @elseif ($step === 2)
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <x-ui.card class="p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-slate-900">Audience</h2>
                        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-xs font-medium">
                            <button type="button" wire:click="$set('audienceMode', 'groups')" @class(['rounded-md px-3 py-1.5', 'bg-white shadow-sm text-slate-900' => $audienceMode === 'groups', 'text-slate-500' => $audienceMode !== 'groups'])>Contact groups</button>
                            <button type="button" wire:click="$set('audienceMode', 'contacts')" @class(['rounded-md px-3 py-1.5', 'bg-white shadow-sm text-slate-900' => $audienceMode === 'contacts', 'text-slate-500' => $audienceMode !== 'contacts'])>Individual contacts</button>
                        </div>
                    </div>

                    @error('audience')
                        <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">{{ $message }}</p>
                    @enderror

                    @if ($audienceMode === 'groups')
                        <div class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            @forelse ($groups as $groupItem)
                                <label class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                    <span class="flex items-center gap-3">
                                        <input type="checkbox" value="{{ $groupItem->id }}" wire:model.live="groupIds" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-900">{{ $groupItem->name }}</span>
                                            <span class="block text-xs text-slate-500">{{ $groupItem->contacts_count }} contacts</span>
                                        </span>
                                    </span>
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ $groupItem->subscribed_count }} eligible</span>
                                </label>
                            @empty
                                <p class="px-4 py-6 text-center text-sm text-slate-500">No contact groups yet — create one first or add individual contacts.</p>
                            @endforelse
                        </div>
                    @else
                        <div class="mt-4">
                            <x-ui.input type="search" wire:model.live.debounce.300ms="contactSearch" placeholder="Search contacts by name or phone" />
                            <div class="mt-3 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                                @forelse ($contactResults as $contact)
                                    <label class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50">
                                        <span class="flex items-center gap-3">
                                            <input type="checkbox" value="{{ $contact->id }}" wire:model.live="contactIds" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            <span>
                                                <span class="block text-sm text-slate-900">{{ $contact->displayName() }}</span>
                                                <span class="block text-xs text-slate-500">{{ format_phone($contact->phone) }}</span>
                                            </span>
                                        </span>
                                        <x-ui.badge :class="$contact->subscription_status->badgeClasses()">{{ $contact->subscription_status->label() }}</x-ui.badge>
                                    </label>
                                @empty
                                    <p class="px-4 py-6 text-center text-sm text-slate-500">No contacts match your search.</p>
                                @endforelse
                            </div>
                            <p class="mt-2 text-xs text-slate-500">{{ $selectedContactTotal }} contact(s) selected. Unsubscribed and suppressed numbers are excluded automatically.</p>
                        </div>
                    @endif
                </x-ui.card>

                @can('import-contacts')
                    <x-ui.card class="p-5">
                        <h2 class="text-sm font-semibold text-slate-900">Add numbers by hand</h2>
                        <p class="mt-1 text-xs text-slate-500">Paste up to 200 numbers, separated by commas or new lines. Missing contacts are created; suppressed numbers are excluded.</p>
                        <div class="mt-3">
                            <x-ui.textarea rows="3" wire:model="bulkNumbers" placeholder="08031234567, 08089876543"></x-ui.textarea>
                            @error('bulkNumbers') <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="mt-3">
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="addNumbers">
                                <span wire:loading.remove wire:target="addNumbers">Add numbers</span>
                                <span wire:loading wire:target="addNumbers">Adding…</span>
                            </x-ui.button>
                        </div>
                    </x-ui.card>
                @endcan
            </div>

            <div class="space-y-4">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Estimated audience</h2>
                    @if (empty($review))
                        <p class="mt-3 text-sm text-slate-500">Select groups or contacts to see the eligible recipient count.</p>
                    @else
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-500">Eligible recipients</dt>
                                <dd class="text-lg font-semibold tabular-nums text-slate-900">{{ number_format($review['recipients']) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-500">Excluded</dt>
                                <dd class="font-medium tabular-nums">{{ number_format($review['excluded_count']) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-500">Estimated SMS segments</dt>
                                <dd class="font-medium tabular-nums">{{ number_format($review['total_segments']) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-slate-500">Max per recipient</dt>
                                <dd class="font-medium tabular-nums">{{ $review['max_segments'] }}</dd>
                            </div>
                        </dl>

                        @if (! empty($review['excluded']))
                            <div class="mt-4 rounded-lg bg-slate-50 p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Exclusions</p>
                                <ul class="mt-2 space-y-1 text-xs text-slate-600">
                                    @foreach ($review['excluded'] as $exclusion)
                                        <li class="flex justify-between"><span>{{ $exclusion['reason'] }}</span><span class="tabular-nums">{{ $exclusion['count'] }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Message</h2>
                    <p class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-800">{{ $previewText }}</p>
                    <p class="mt-2 text-xs text-slate-500">{{ $previewSegments->segments }} segment(s) per message · {{ $previewSegments->encoding->label() }}</p>
                </x-ui.card>
            </div>
        </div>

        <div class="mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3">
            <x-ui.button type="button" variant="secondary" wire:click="previousStep">Back</x-ui.button>
            <div class="flex items-center gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="saveDraft">
                    <span wire:loading.remove wire:target="saveDraft">Save draft</span>
                    <span wire:loading wire:target="saveDraft">Saving…</span>
                </x-ui.button>
                <x-ui.button type="button" wire:click="review" wire:loading.attr="disabled">Review campaign</x-ui.button>
            </div>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <x-ui.card class="p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">{{ $name }}</h2>
                            <p class="text-xs text-slate-500">Review everything carefully — confirming sends real SMS messages.</p>
                        </div>
                        <x-ui.badge class="bg-amber-50 text-amber-700 ring-amber-200">Awaiting confirmation</x-ui.badge>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Recipients</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($review['recipients'] ?? 0) }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">SMS segments (est.)</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($review['total_segments'] ?? 0) }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Max segments / recipient</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $review['max_segments'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Encoding</p>
                            <p class="mt-1 text-sm font-semibold">{{ $review['encoding_label'] ?? '—' }}</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Message template</p>
                        <p class="mt-2 whitespace-pre-line rounded-lg border border-slate-200 p-3 text-sm text-slate-800">{{ $message }}</p>
                    </div>

                    @if (! empty($sampleRecipients))
                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personalised samples</p>
                            <div class="mt-2 space-y-2">
                                @foreach ($sampleRecipients as $sample)
                                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                                        <p class="text-xs text-slate-500">{{ $sample['name'] ?: format_phone($sample['phone']) }} · {{ $sample['segments'] }} segment(s)</p>
                                        <p class="mt-1 whitespace-pre-line text-slate-800">{{ $sample['message'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Excluded recipients</h2>
                    @if (empty($review['excluded']))
                        <p class="mt-2 text-sm text-slate-500">Nobody in the selected audience was excluded.</p>
                    @else
                        <ul class="mt-3 divide-y divide-slate-100">
                            @foreach ($review['excluded'] as $exclusion)
                                <li class="flex items-center justify-between py-2 text-sm">
                                    <span class="text-slate-600">{{ $exclusion['reason'] }}</span>
                                    <span class="font-medium tabular-nums">{{ number_format($exclusion['count']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-slate-500">These numbers will never be messaged while they are unsubscribed or suppressed.</p>
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-4">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Send settings</h2>
                    <dl class="mt-3 divide-y divide-slate-100">
                        <x-ui.details label="Sender ID" :value="$sender_id" />
                        <x-ui.details label="Channel" :value="$channel === 'dnd' ? 'DND (transactional)' : 'Generic (promotional)'" />
                        <x-ui.details label="Dispatch mode" :value="$campaign?->dispatch_mode?->label() ?? 'Bulk (batched)'" />
                        <x-ui.details label="Send time" :value="$schedule && $scheduled_at ? \Illuminate\Support\Carbon::parse($scheduled_at, $currentTimezone)->toDayDateTimeString().' ('.$currentTimezone.')' : 'Immediately after confirmation'" />
                        <x-ui.details label="Termii balance" :value="$providerStatus->balance !== null ? number_format((float) $providerStatus->balance, 2).' '.($providerStatus->currency ?? 'NGN') : 'Not checked yet'" />
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">
                        Estimated SMS units are not a price estimate. Check your Termii tariff for costs.
                    </p>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Confirm</h2>
                    <div class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
                        Confirming will {{ $schedule ? 'schedule' : 'immediately queue' }} this campaign for {{ number_format($review['recipients'] ?? 0) }} recipients. Repeated clicks or refreshes cannot send it twice.
                    </div>

                    <div class="mt-4">
                        <x-ui.checkbox wire:model.live="confirmed">
                            I have reviewed the recipients and the message.
                        </x-ui.checkbox>
                        @error('confirmed')
                            <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                        @enderror
                        @error('message')
                            <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-5 space-y-2">
                        @if ($canSend)
                            <x-ui.button type="button" class="w-full" wire:click="confirmSend" wire:loading.attr="disabled" wire:target="confirmSend">
                                <span wire:loading.remove wire:target="confirmSend">{{ $schedule ? 'Confirm & schedule' : 'Confirm & send' }}</span>
                                <span wire:loading wire:target="confirmSend">Submitting…</span>
                            </x-ui.button>
                        @else
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                You do not have permission to send campaigns. Submit it for an administrator to authorise.
                            </p>
                            <x-ui.button type="button" class="w-full" wire:click="submitForReview">
                                Submit for authorisation
                            </x-ui.button>
                        @endif

                        @if ($canSend)
                            <x-ui.button type="button" variant="secondary" class="w-full" wire:click="submitForReview">
                                Submit for authorisation instead
                            </x-ui.button>
                        @endif

                        <x-ui.button type="button" variant="ghost" class="w-full" wire:click="backToAudience">Back to recipients</x-ui.button>
                    </div>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
