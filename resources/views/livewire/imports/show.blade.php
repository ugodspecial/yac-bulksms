<div>
    <x-ui.page-header :title="$import->original_filename" :subtitle="'Uploaded '.$import->created_at->timezone(settings()->timezone())->toDayDateTimeString().' by '.($import->user?->name ?? 'Unknown user')">
        <x-slot:actions>
            <x-ui.button href="{{ route('imports.index') }}" variant="secondary" size="sm">Back to imports</x-ui.button>

            @can('commit', $import)
                @if ($import->status === App\Enums\ImportStatus::Ready && $import->valid_rows > 0)
                    <x-ui.button wire:click="commit" size="sm" wire:loading.attr="disabled" wire:target="commit">
                        <span wire:loading.remove wire:target="commit">Import {{ number_format($import->valid_rows) }} contact(s)</span>
                        <span wire:loading wire:target="commit">Starting…</span>
                    </x-ui.button>
                @endif
            @endcan

            @can('delete', $import)
                <x-ui.button variant="danger" size="sm" wire:click="delete" wire:confirm="Delete this import and its row log? Contacts already imported are kept.">
                    Delete
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-ui.badge :class="$import->status->badgeClasses()">{{ $import->status->label() }}</x-ui.badge>
        <x-ui.badge class="bg-slate-100 text-slate-600 ring-slate-200">{{ strtoupper($import->file_type) }}</x-ui.badge>
        @if ($import->group)
            <x-ui.badge class="bg-indigo-50 text-indigo-700 ring-indigo-200">Group: {{ $import->group->name }}</x-ui.badge>
        @endif
        @if ($isBusy)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500"></span> Working in the background
            </span>
        @endif
    </div>

    @if ($import->failure_reason)
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            <p class="font-medium">Import failed</p>
            <p class="mt-1">{{ $import->failure_reason }}</p>
            @if ($import->status === App\Enums\ImportStatus::Failed)
                <div class="mt-3">
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="retryValidation">Retry validation</x-ui.button>
                </div>
            @endif
            @error('retry') <p class="mt-2 text-xs font-medium text-rose-700">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($import->status === App\Enums\ImportStatus::Importing || $import->status === App\Enums\ImportStatus::Completed)
        <div class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between text-sm">
                <p class="font-medium text-slate-900">Import progress</p>
                <p class="tabular-nums text-slate-600">{{ number_format($counts['imported']) }} / {{ number_format($counts['valid']) }} contacts</p>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                <div class="h-full rounded-full bg-indigo-500 transition-all" style="width: {{ $import->progressPercent() }}%"></div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
        <x-ui.stat label="Rows found" :value="number_format($counts['total'])" icon="collection" tone="slate" />
        <x-ui.stat label="Valid" :value="number_format($counts['valid'])" hint="Ready to import" icon="check" tone="emerald" />
        <x-ui.stat label="Invalid" :value="number_format($counts['invalid'])" hint="Fixable in source file" icon="alert" tone="rose" />
        <x-ui.stat label="Duplicates" :value="number_format($counts['duplicate'])" hint="Skipped" icon="shield" tone="amber" />
        <x-ui.stat label="Imported" :value="number_format($counts['imported'])" hint="Contacts written" icon="users" tone="indigo" />
    </div>

    @error('commit')
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ $message }}</div>
    @enderror

    <x-ui.card class="mt-4">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
            @foreach ($rowStatuses as $status)
                <button
                    type="button"
                    wire:click="$set('rowStatus', '{{ $status->value }}')"
                    @class([
                        'rounded-full px-3 py-1 text-xs font-medium transition',
                        'bg-indigo-600 text-white' => $rowStatus === $status->value,
                        'bg-slate-100 text-slate-600 hover:bg-slate-200' => $rowStatus !== $status->value,
                    ])
                >
                    {{ $status->label() }}
                </button>
            @endforeach
            <button
                type="button"
                wire:click="$set('rowStatus', '')"
                @class([
                    'rounded-full px-3 py-1 text-xs font-medium transition',
                    'bg-indigo-600 text-white' => $rowStatus === '',
                    'bg-slate-100 text-slate-600 hover:bg-slate-200' => $rowStatus !== '',
                ])
            >
                All rows
            </button>
        </div>

        @if ($rows->isEmpty())
            <x-ui.empty-state
                title="No rows in this view"
                description="Row-level validation results appear here after the file has been processed."
                icon="collection"
            />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead class="table-head">
                        <tr>
                            <th class="px-4 py-2">Row</th>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Phone (raw)</th>
                            <th class="px-4 py-2">Phone (normalised)</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Problem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="table-cell text-xs tabular-nums text-slate-500">{{ $row->row_number }}</td>
                                <td class="table-cell">{{ $row->name ?: '—' }}</td>
                                <td class="table-cell font-mono text-xs">{{ $row->phone_raw ?: '—' }}</td>
                                <td class="table-cell font-mono text-xs">{{ $row->phone_normalized ? format_phone($row->phone_normalized) : '—' }}</td>
                                <td class="table-cell text-xs text-slate-600">{{ $row->email ?: '—' }}</td>
                                <td class="table-cell">
                                    <x-ui.badge :class="$row->status->badgeClasses()">{{ $row->status->label() }}</x-ui.badge>
                                </td>
                                <td class="table-cell max-w-[260px] text-xs text-slate-600">{{ $row->error ?: '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $rows->links() }}</div>
        @endif
    </x-ui.card>

    <p class="mt-4 text-xs text-slate-500">
        Invalid and duplicate rows are never imported. Re-upload a corrected file to import the remaining contacts.
    </p>
</div>
