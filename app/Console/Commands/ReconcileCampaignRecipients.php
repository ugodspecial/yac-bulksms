<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Jobs\ReconcileCampaignRecipientsJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaign\CampaignProgress;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Console\Command;

class ReconcileCampaignRecipients extends Command
{
    protected $signature = 'campaigns:reconcile {--limit=200 : Maximum accepted messages to reconcile per run}';

    protected $description = 'Poll Termii for delivery reports and flag interrupted sends for reconciliation';

    public function handle(
        SettingsService $settings,
        TermiiGatewayInterface $gateway,
        CampaignProgress $progress,
    ): int {
        $staleMinutes = max(5, (int) config('yac.stale_claim_minutes', 15));

        $stale = CampaignRecipient::query()
            ->where('status', RecipientStatus::Processing->value)
            ->where('claimed_at', '<', now()->subMinutes($staleMinutes))
            ->limit(500)
            ->get();

        $campaignIds = [];

        foreach ($stale as $recipient) {
            $recipient->forceFill([
                'status' => RecipientStatus::Unknown,
                'ambiguous' => true,
                'provider_status' => 'stale_claim',
                'error_code' => 'job_interrupted',
                'error_message' => 'The worker stopped before the outcome was recorded. Verify this message in Termii before resending.',
                'claim_token' => null,
                'claimed_at' => null,
                'last_reconciled_at' => now(),
            ])->save();

            $campaignIds[$recipient->campaign_id] = true;
        }

        if ($stale->isNotEmpty()) {
            $this->warn(sprintf('Flagged %d interrupted recipient(s) for reconciliation.', $stale->count()));
        }

        foreach (array_keys($campaignIds) as $campaignId) {
            $campaign = Campaign::query()->find($campaignId);

            if ($campaign !== null) {
                $progress->refresh($campaign);
            }
        }

        if (! $settings->reconcileEnabled() || ! $gateway->isConfigured()) {
            $this->info('Delivery reconciliation is disabled or Termii is not configured.');

            return self::SUCCESS;
        }

        $ids = CampaignRecipient::query()
            ->where('status', RecipientStatus::Accepted->value)
            ->whereNotNull('provider_message_id')
            ->where(function ($query): void {
                $query->whereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<', now()->subMinutes(30));
            })
            ->where('submitted_at', '<=', now()->subMinutes(2))
            ->whereHas('campaign', fn ($query) => $query->whereNotIn('status', [
                CampaignStatus::Scheduled->value,
                CampaignStatus::Draft->value,
            ]))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, 25) as $chunk) {
            ReconcileCampaignRecipientsJob::dispatch($chunk);
        }

        $this->info(sprintf('Queued %d message(s) for delivery reconciliation.', count($ids)));

        return self::SUCCESS;
    }
}
