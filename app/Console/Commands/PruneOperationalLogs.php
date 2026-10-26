<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\ContactImport;
use App\Models\ProviderWebhookEvent;
use App\Models\SmsLog;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

class PruneOperationalLogs extends Command
{
    protected $signature = 'logs:prune {--days= : Override the configured retention period} {--dry-run}';

    protected $description = 'Delete operational logs older than the retention period';

    public function handle(SettingsService $settings): int
    {
        $days = (int) ($this->option('days') ?: $settings->retentionDays());
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $smsLogs = SmsLog::query()->where('created_at', '<', $cutoff);
        $webhooks = ProviderWebhookEvent::query()->where('created_at', '<', $cutoff);
        $imports = ContactImport::query()
            ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::Failed->value])
            ->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: would delete %d SMS log(s), %d webhook event(s) and %d import record(s) older than %s.',
                $smsLogs->count(),
                $webhooks->count(),
                $imports->count(),
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $deletedSms = $smsLogs->delete();
        $deletedWebhooks = $webhooks->delete();
        $deletedImports = $imports->delete();

        // Imports that never finished (for example after a crash) are marked
        // as failed after 24 hours so operators are not left waiting forever.
        $stuck = ContactImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Validating->value, ImportStatus::Importing->value])
            ->where('updated_at', '<', now()->subDay())
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure_reason' => 'The import did not finish in time. Please upload the file again.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $this->info(sprintf(
            'Pruned %d SMS log(s), %d webhook event(s) and %d import record(s) older than %s. Flagged %d stale import(s).',
            $deletedSms,
            $deletedWebhooks,
            $deletedImports,
            $cutoff->toDateString(),
            $stuck,
        ));

        return self::SUCCESS;
    }
}
