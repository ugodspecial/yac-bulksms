<div>
    <x-ui.page-header
        title="Contact imports"
        subtitle="Upload a CSV or Excel file, review row errors, then commit the valid rows in the background."
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('contacts.index') }}" variant="secondary" size="sm">
                <x-ui.icon name="users" class="h-4 w-4" /> All contacts
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        @can('create', App\Models\ContactImport::class)
            <x-ui.card class="p-5 lg:col-span-1">
                <h2 class="text-sm font-semibold text-slate-900">Upload a file</h2>
                <p class="mt-1 text-xs text-slate-500">
                    CSV or Excel (.xlsx). Up to {{ number_format($maxRows) }} rows and {{ number_format($maxSizeKb / 1024, 1) }} MB per file.
                    The first row must contain column headers.
                </p>

                <form wire:submit="upload" class="mt-4 space-y-4">
                    <div>
                        <label for="import-file" class="block text-sm font-medium text-slate-700">Spreadsheet</label>
                        <input
                            id="import-file"
                            type="file"
                            accept=".csv,.xlsx,.txt"
                            wire:model="file"
                            class="mt-1 block w-full cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700"
                        >
                        @error('file') <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                        <p wire:loading wire:target="file" class="mt-1 text-xs text-slate-500">Uploading…</p>
                    </div>

                    <div>
                        <label for="import-group" class="block text-sm font-medium text-slate-700">Add valid contacts to a group (optional)</label>
                        <select id="import-group" wire:model="groupId" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">No group</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                        @error('groupId') <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="upload,file">
                        <x-ui.icon name="upload" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="upload">Upload & validate</span>
                        <span wire:loading wire:target="upload">Uploading…</span>
                    </x-ui.button>
                </form>

                <div class="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                    <p class="font-semibold text-slate-700">Expected columns</p>
                    <p class="mt-1">Any file with a <code class="rounded bg-white px-1">phone</code> column. Optional: <code class="rounded bg-white px-1">name</code>, <code class="rounded bg-white px-1">email</code> and any custom field columns.</p>
                    <p class="mt-2">Numbers are normalised to E.164. Duplicates inside the file and existing contacts are flagged before anything is imported.</p>
                </div>
            </x-ui.card>
        @endcan

        <x-ui.card class="lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Import history</h2>
                @if ($busy)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500"></span> Background work in progress
                    </span>
                @endif
            </div>

            @if ($imports->isEmpty())
                <x-ui.empty-state
                    title="No imports yet"
                    description="Uploaded files and their validation results appear here."
                    icon="collection"
                />
            @else
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead class="table-head">
                            <tr>
                                <th class="px-4 py-2">File</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Rows</th>
                                <th class="px-4 py-2">Imported</th>
                                <th class="px-4 py-2">Uploaded</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($imports as $import)
                                <tr class="hover:bg-slate-50">
                                    <td class="table-cell">
                                        <p class="max-w-[220px] truncate font-medium text-slate-900">{{ $import->original_filename }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $import->user?->name ?? 'Unknown user' }}
                                            @if ($import->group) · {{ $import->group->name }} @endif
                                        </p>
                                    </td>
                                    <td class="table-cell">
                                        <x-ui.badge :class="$import->status->badgeClasses()">{{ $import->status->label() }}</x-ui.badge>
                                    </td>
                                    <td class="table-cell text-xs text-slate-600">
                                        {{ number_format($import->total_rows) }} total<br>
                                        <span class="text-rose-600">{{ number_format($import->invalid_rows) }} invalid</span>
                                        · <span class="text-amber-600">{{ number_format($import->duplicate_rows) }} duplicate</span>
                                    </td>
                                    <td class="table-cell text-sm tabular-nums">{{ number_format($import->imported_rows) }}</td>
                                    <td class="table-cell text-xs text-slate-500">{{ $import->created_at->timezone(settings()->timezone())->toDayDateTimeString() }}</td>
                                    <td class="table-cell text-right">
                                        <a href="{{ route('imports.show', $import) }}" class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3">{{ $imports->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</div>
