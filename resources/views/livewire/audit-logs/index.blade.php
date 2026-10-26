<div>
    <x-ui.page-header
        title="Audit logs"
        subtitle="Who did what, when and from where. Entries are append-only and shown newest first."
    />

    <x-ui.card>
        <div class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <x-ui.select wire:model.live="action">
                <option value="">All actions</option>
                @foreach ($actions as $actionOption)
                    <option value="{{ $actionOption }}">{{ $actionOption }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="userId">
                <option value="">All users</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search description" />

            <x-ui.field label="From" name="from">
                <x-ui.input type="date" wire:model.live="from" />
            </x-ui.field>

            <x-ui.field label="To" name="to">
                <x-ui.input type="date" wire:model.live="to" />
            </x-ui.field>

            <div class="flex items-end">
                <x-ui.button type="button" variant="secondary" size="sm" class="w-full" wire:click="$set('action', ''); $set('userId', ''); $set('search', ''); $set('from', ''); $set('to', '')">
                    Reset
                </x-ui.button>
            </div>
        </div>

        @if ($logs->isEmpty())
            <x-ui.empty-state
                title="No audit entries match these filters"
                description="Sign-ins, campaign confirmations, contact changes and settings updates are recorded here."
                icon="book"
            />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">When</th>
                            <th class="px-4 py-2">User</th>
                            <th class="px-4 py-2">Action</th>
                            <th class="px-4 py-2">Description</th>
                            <th class="px-4 py-2">IP address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($logs as $log)
                            <tr class="align-top hover:bg-slate-50">
                                <td class="table-cell whitespace-nowrap text-xs text-slate-500">
                                    {{ $log->created_at->timezone(settings()->timezone())->toDayDateTimeString() }}
                                </td>
                                <td class="table-cell text-sm text-slate-700">
                                    {{ $log->user?->name ?? 'System' }}
                                </td>
                                <td class="table-cell">
                                    <span class="whitespace-nowrap rounded bg-slate-100 px-2 py-0.5 font-mono text-[11px] text-slate-600">{{ $log->action }}</span>
                                </td>
                                <td class="table-cell text-sm text-slate-700">
                                    <p>{{ $log->description }}</p>
                                    @if (! empty($log->meta))
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-xs text-indigo-600">Details</summary>
                                            <pre class="mt-1 max-w-xl overflow-x-auto rounded bg-slate-50 p-2 text-[11px] text-slate-600">{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @endif
                                </td>
                                <td class="table-cell font-mono text-xs text-slate-500">{{ $log->ip_address ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $logs->links() }}</div>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-slate-500">
        Audit entries are pruned by the scheduled log cleanup task according to the retention setting.
    </p>
</div>
