<div>
    <x-ui.page-header
        title="Settings"
        subtitle="Application, messaging, import and provider settings. Only administrators can change these values."
    />

    @if ($providerResult)
        <div @class([
            'mb-4 rounded-xl border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-900' => $providerResult['type'] === 'success',
            'border-amber-200 bg-amber-50 text-amber-900' => $providerResult['type'] === 'warning',
            'border-rose-200 bg-rose-50 text-rose-900' => $providerResult['type'] === 'error',
        ]) role="status">
            {{ $providerResult['message'] }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form wire:submit="save" class="space-y-4">
                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Branding</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Application name" name="appName" hint="Shown in the sidebar and message subjects.">
                            <x-ui.input wire:model="appName" />
                        </x-ui.field>

                        <x-ui.field label="Timezone" name="timezone" hint="Scheduled campaigns and timestamps use this timezone.">
                            <x-ui.select wire:model="timezone">
                                @foreach ($timezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>

                    <div class="mt-4">
                        <x-ui.field label="Logo" name="logo" hint="PNG, JPG or WEBP, max 2 MB.">
                            <input type="file" accept="image/png,image/jpeg,image/webp" wire:model="logo" class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700">
                        </x-ui.field>
                        @if ($logoPath)
                            <div class="mt-3 flex items-center gap-3">
                                <img src="{{ asset('storage/'.$logoPath) }}" alt="Current logo" class="h-10 w-10 rounded-lg border border-slate-200 object-contain p-1">
                                <x-ui.checkbox wire:model="removeLogo" label="Remove the current logo when saving" />
                            </div>
                        @endif
                        <p wire:loading wire:target="logo" class="mt-1 text-xs text-slate-500">Uploading…</p>
                    </div>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Messaging defaults</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Default sender ID" name="defaultSenderId" hint="Must be approved on the Termii account.">
                            <x-ui.input wire:model="defaultSenderId" placeholder="YAC" />
                        </x-ui.field>

                        <x-ui.field label="Approved sender IDs" name="approvedSenderIds" hint="Comma separated. Operators can only choose from this list.">
                            <x-ui.input wire:model="approvedSenderIds" placeholder="YAC, YAC Alerts" />
                        </x-ui.field>

                        <x-ui.field label="Default channel" name="defaultChannel">
                            <x-ui.select wire:model="defaultChannel">
                                <option value="generic">Generic (promotional)</option>
                                <option value="dnd">DND (transactional)</option>
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Batch size" name="batchSize" hint="Recipients per provider request. Termii allows at most 100.">
                            <x-ui.input type="number" min="1" max="100" wire:model="batchSize" />
                        </x-ui.field>

                        <x-ui.field label="Maximum message length" name="maxMessageLength" hint="Characters. Longer messages are rejected before sending.">
                            <x-ui.input type="number" min="100" max="5000" wire:model="maxMessageLength" />
                        </x-ui.field>

                        <x-ui.field label="Maximum segments" name="maxSegments" hint="Campaigns that would exceed this many segments are blocked.">
                            <x-ui.input type="number" min="1" max="20" wire:model="maxSegments" />
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            <x-ui.field label="Custom fields" name="customFields" hint="Comma separated keys available as {{ '{{custom.key}}' }} in messages, for example: department, account_number.">
                                <x-ui.input wire:model="customFields" placeholder="department, account_number" />
                            </x-ui.field>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Sending controls</h2>
                    <div class="mt-4 space-y-4">
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-4">
                            <x-ui.checkbox wire:model="sendingPaused" label="Pause all outgoing SMS" />
                            <p class="mt-1 text-xs text-rose-900">
                                While paused, queued batches stop before contacting Termii. Campaigns already confirmed stay queued and resume when you unpause.
                            </p>
                        </div>

                        <x-ui.field label="Opt-out keywords" name="optOutKeywords" hint="Comma separated replies that suppress a number (requires the inbound webhook).">
                            <x-ui.input wire:model="optOutKeywords" placeholder="STOP, UNSUBSCRIBE" />
                        </x-ui.field>

                        <x-ui.checkbox wire:model="reconcileEnabled" label="Reconcile ambiguous timeouts with the provider" />
                        <p class="-mt-2 text-xs text-slate-500">
                            When a request times out with an unknown outcome, the scheduler checks Termii for the final status instead of resending.
                        </p>
                    </div>
                </x-ui.card>

                <x-ui.card class="p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Contact imports &amp; retention</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <x-ui.field label="Default country" name="defaultCountry" hint="Two-letter code used to normalise local numbers.">
                            <x-ui.input wire:model="defaultCountry" maxlength="2" />
                        </x-ui.field>
                        <x-ui.field label="Row limit" name="importMaxRows">
                            <x-ui.input type="number" min="1" max="100000" wire:model="importMaxRows" />
                        </x-ui.field>
                        <x-ui.field label="File size (KB)" name="importMaxSizeKb">
                            <x-ui.input type="number" min="64" max="20480" wire:model="importMaxSizeKb" />
                        </x-ui.field>
                        <x-ui.field label="Log retention (days)" name="retentionDays" hint="Used by the log pruning command.">
                            <x-ui.input type="number" min="7" max="3650" wire:model="retentionDays" />
                        </x-ui.field>
                    </div>
                </x-ui.card>

                <div class="flex items-center justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save,logo">
                        <span wire:loading.remove wire:target="save">Save settings</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                </div>
            </form>
        </div>

        <div class="space-y-4">
            <x-ui.card class="p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Termii connection</h2>
                    @php
                        $providerTone = match ($providerStatus->status) {
                            'ok' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                            'degraded' => 'bg-amber-50 text-amber-700 ring-amber-200',
                            'error' => 'bg-rose-50 text-rose-700 ring-rose-200',
                            default => 'bg-slate-100 text-slate-600 ring-slate-200',
                        };
                    @endphp
                    <x-ui.badge :class="$providerTone">{{ ucfirst($providerStatus->status) }}</x-ui.badge>
                </div>

                <dl class="mt-3 divide-y divide-slate-100">
                    <x-ui.details label="API key" :value="$maskedKey" />
                    <x-ui.details label="Endpoint host" :value="$baseUrlHost" />
                    <x-ui.details label="Last balance" :value="$lastBalanceLabel" />
                    <x-ui.details label="Checked" :value="$checkedAtLabel" />
                    <x-ui.details label="Sender ID" :value="$senderIdLabel" />
                </dl>

                @if ($providerStatus->last_error)
                    <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-900">{{ $providerStatus->last_error }}</p>
                @endif

                <div class="mt-4 space-y-2">
                    <x-ui.button type="button" variant="secondary" class="w-full" size="sm" wire:click="checkBalance" wire:loading.attr="disabled" wire:target="checkBalance">
                        <span wire:loading.remove wire:target="checkBalance">Check balance</span>
                        <span wire:loading wire:target="checkBalance">Contacting Termii…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" class="w-full" size="sm" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading.remove wire:target="testConnection">Test connection</span>
                        <span wire:loading wire:target="testConnection">Testing…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" class="w-full" size="sm" wire:click="validateSender" wire:loading.attr="disabled" wire:target="validateSender">
                        <span wire:loading.remove wire:target="validateSender">Validate sender ID</span>
                        <span wire:loading wire:target="validateSender">Validating…</span>
                    </x-ui.button>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    The API key is configured in the server environment (TERMII_API_KEY) and is never displayed or editable here.
                </p>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-slate-900">Delivery reports</h2>
                <dl class="mt-3 divide-y divide-slate-100">
                    <x-ui.details label="Webhook" :value="$webhookEnabledLabel" />
                    <x-ui.details label="Signing secret" :value="$webhookSecretLabel" />
                </dl>
                <p class="mt-3 text-xs text-slate-600">
                    Webhook URL: <code class="break-all rounded bg-slate-100 px-1">{{ url('/webhooks/termii') }}</code>
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    Register this URL in the Termii dashboard and enable the webhook in the server environment so delivery reports can update this application.
                    Without it, messages remain marked as accepted.
                </p>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-slate-900">Queue health</h2>
                <dl class="mt-3 divide-y divide-slate-100">
                    <x-ui.details label="Connection" :value="$queue['connection']" />
                    <x-ui.details label="Pending jobs" :value="number_format($queue['pending'])" />
                    <x-ui.details label="Failed jobs" :value="number_format($queue['failed'])" />
                    <x-ui.details label="Oldest pending" :value="$oldestPendingLabel" />
                </dl>
                @if ($queue['failed'] > 0)
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Failed jobs need attention. Review the logs and retry them with <code class="rounded bg-white px-1">php artisan queue:retry all</code> once the cause is fixed.
                    </p>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
