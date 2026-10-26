<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Contacts\ContactImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Writes the validated rows of an import into the contacts table. */
class CommitContactImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $importId,
        public readonly ?int $userId = null,
    ) {}

    public function handle(ContactImportProcessor $processor): void
    {
        $import = ContactImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        // Idempotent: only a validated, ready import may be committed once.
        if ($import->status !== ImportStatus::Ready) {
            return;
        }

        $import->forceFill(['status' => ImportStatus::Importing])->save();

        $actor = $this->userId !== null ? User::query()->find($this->userId) : null;

        $processor->commit($import, $actor);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Contact import commit job failed', [
            'import_id' => $this->importId,
            'error' => $e?->getMessage(),
        ]);

        ContactImport::query()
            ->whereKey($this->importId)
            ->where('status', ImportStatus::Importing->value)
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure_reason' => 'The contacts could not be saved. Please review the file and try again.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
