<?php

namespace App\Livewire\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Jobs\CommitContactImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\ContactImport;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Import')]
class Show extends Component
{
    use WithPagination;

    public ContactImport $import;

    #[Url(except: 'invalid')]
    public string $rowStatus = 'invalid';

    public function mount(ContactImport $import): void
    {
        Gate::authorize('view', $import);

        $this->import = $import;
    }

    public function commit(AuditLogger $audit): void
    {
        Gate::authorize('commit', $this->import);

        $this->import->refresh();

        if ($this->import->status !== ImportStatus::Ready) {
            $this->addError('commit', 'This import is not ready to be committed yet.');

            return;
        }

        if ($this->import->valid_rows === 0) {
            $this->addError('commit', 'This file has no valid rows to import.');

            return;
        }

        $this->import->forceFill(['status' => ImportStatus::Importing])->save();

        CommitContactImportJob::dispatch($this->import->id, auth()->id());

        $audit->log('import.commit_requested', $this->import, sprintf(
            'Started committing %d contact(s) from "%s".',
            $this->import->valid_rows,
            $this->import->original_filename,
        ));

        $this->import->refresh();

        $this->dispatch('notify', message: 'Import started in the background.', type: 'success');
    }

    public function retryValidation(): void
    {
        Gate::authorize('view', $this->import);

        $this->import->refresh();

        if ($this->import->status !== ImportStatus::Failed) {
            return;
        }

        if (! Storage::disk($this->import->disk)->exists($this->import->stored_path)) {
            $this->addError('retry', 'The uploaded file is no longer available. Please upload it again.');

            return;
        }

        $this->import->forceFill([
            'status' => ImportStatus::Pending,
            'failure_reason' => null,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'total_rows' => 0,
            'imported_rows' => 0,
        ])->save();

        ProcessContactImportJob::dispatch($this->import->id);

        $this->import->refresh();
        $this->dispatch('notify', message: 'Validation restarted.', type: 'info');
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->import);

        $import = $this->import;

        try {
            Storage::disk($import->disk)->delete($import->stored_path);
        } catch (\Throwable) {
            // Ignore missing files.
        }

        $import->delete();

        session()->flash('success', 'Import deleted.');
        $this->redirectRoute('imports.index');
    }

    public function render(): View
    {
        $rows = $this->import->rows()
            ->when($this->rowStatus !== '', fn ($query) => $query->where('status', $this->rowStatus))
            ->orderBy('row_number')
            ->paginate(25);

        return view('livewire.imports.show', [
            'rows' => $rows,
            'counts' => [
                'total' => $this->import->total_rows,
                'valid' => $this->import->valid_rows,
                'invalid' => $this->import->invalid_rows,
                'duplicate' => $this->import->duplicate_rows,
                'imported' => $this->import->imported_rows,
            ],
            'rowStatuses' => ImportRowStatus::cases(),
            'isBusy' => $this->import->status->isBusy(),
        ]);
    }
}
