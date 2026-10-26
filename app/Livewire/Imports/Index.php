<?php

namespace App\Livewire\Imports;

use App\Enums\ImportStatus;
use App\Jobs\ProcessContactImportJob;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Contact imports')]
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file;

    #[Url(except: '')]
    public string $groupId = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ContactImport::class);
    }

    public function upload(AuditLogger $audit): void
    {
        Gate::authorize('create', ContactImport::class);

        $this->validate([
            'file' => [
                'required',
                'file',
                'extensions:csv,xlsx,txt',
                'max:'.settings()->importMaxSizeKb(),
            ],
            'groupId' => ['nullable', 'integer', 'exists:contact_groups,id'],
        ], [], [
            'file' => 'import file',
            'groupId' => 'contact group',
        ]);

        $extension = strtolower((string) $this->file->getClientOriginalExtension());
        $type = $extension === 'xlsx' ? 'xlsx' : 'csv';

        $storedPath = $this->file->store('imports', 'local');

        $import = ContactImport::create([
            'user_id' => auth()->id(),
            'contact_group_id' => $this->groupId !== '' ? (int) $this->groupId : null,
            'original_filename' => mb_substr($this->file->getClientOriginalName(), 0, 191),
            'stored_path' => $storedPath,
            'disk' => 'local',
            'file_type' => $type,
            'status' => ImportStatus::Pending,
            'max_rows' => settings()->importMaxRows(),
        ]);

        ProcessContactImportJob::dispatch($import->id);

        $audit->log('import.uploaded', $import, sprintf(
            'Uploaded "%s" for validation (%d KB).',
            $import->original_filename,
            (int) ceil($this->file->getSize() / 1024),
        ), ['file_type' => $type]);

        $this->reset('file');

        session()->flash('success', 'File uploaded. It is being validated in the background.');
        $this->redirectRoute('imports.show', ['import' => $import->id]);
    }

    public function render(): View
    {
        $imports = ContactImport::query()
            ->with('user:id,name', 'group:id,name')
            ->latest()
            ->paginate(15);

        return view('livewire.imports.index', [
            'imports' => $imports,
            'groups' => ContactGroup::query()->orderBy('name')->get(['id', 'name']),
            'maxRows' => settings()->importMaxRows(),
            'maxSizeKb' => settings()->importMaxSizeKb(),
            'busy' => ContactImport::query()->whereIn('status', [
                ImportStatus::Pending->value,
                ImportStatus::Validating->value,
                ImportStatus::Importing->value,
            ])->exists(),
        ]);
    }
}
