<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ContactImport;
use App\Services\Contacts\ContactImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Validates an uploaded contact file in the background. */
class ProcessContactImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $importId) {}

    public function handle(ContactImportProcessor $processor): void
    {
        $import = ContactImport::query()->find($this->importId);

        if ($import === null || $import->status !== ImportStatus::Pending) {
            return;
        }

        $processor->validate($import);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Contact import validation job failed', [
            'import_id' => $this->importId,
            'error' => $e?->getMessage(),
        ]);

        ContactImport::query()
            ->whereKey($this->importId)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Validating->value])
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure_reason' => 'The import could not be validated. Please try again.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
