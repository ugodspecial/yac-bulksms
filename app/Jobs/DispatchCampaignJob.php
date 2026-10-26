<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Campaign\CampaignProgress;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Splits a confirmed campaign into recipient batches and queues one job per
 * batch. Unique per campaign, so a duplicated confirmation cannot dispatch the
 * audience twice.
 */
class DispatchCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $campaignId) {}

    public function uniqueId(): string
    {
        return 'campaign-dispatch-'.$this->campaignId;
    }

    public function handle(CampaignProgress $progress, SettingsService $settings): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        // Only queued/processing campaigns are dispatched. Scheduled campaigns
        // are released by the scheduler, terminal ones are ignored.
        if (! in_array($campaign->status, [CampaignStatus::Queued, CampaignStatus::Processing], true)) {
            return;
        }

        if ($campaign->status === CampaignStatus::Queued) {
            $campaign->forceFill([
                'status' => CampaignStatus::Processing,
                'processing_started_at' => $campaign->processing_started_at ?? now(),
            ])->save();
        }

        $size = max(1, min(100, (int) ($campaign->batch_size ?: $settings->batchSize())));

        $ids = $campaign->recipients()
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Processing->value])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, $size) as $index => $chunk) {
            SendCampaignBatchJob::dispatch($campaign->id, $chunk, $index);
        }

        $progress->refresh($campaign);
    }
}
