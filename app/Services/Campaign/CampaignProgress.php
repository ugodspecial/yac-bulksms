<?php

namespace App\Services\Campaign;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates a campaign's status from the state of its recipients. Safe to
 * call from any worker: it only finalises when no recipient is pending or in
 * flight, so concurrent batches cannot mark a campaign complete early.
 */
class CampaignProgress
{
    public function refresh(Campaign $campaign): Campaign
    {
        $campaign->refresh();

        if ($campaign->status->isTerminal()) {
            return $campaign;
        }

        /** @var array<string, int> $counts */
        $counts = $campaign->recipients()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $pending = (int) ($counts[RecipientStatus::Pending->value] ?? 0)
            + (int) ($counts[RecipientStatus::Processing->value] ?? 0);

        $changes = [];

        if ($campaign->status === CampaignStatus::Queued) {
            $changes['status'] = CampaignStatus::Processing;
            $changes['processing_started_at'] = $campaign->processing_started_at ?? now();
        }

        if ($pending === 0) {
            $problemCount = (int) ($counts[RecipientStatus::Failed->value] ?? 0)
                + (int) ($counts[RecipientStatus::Unknown->value] ?? 0);

            $changes['status'] = $problemCount > 0
                ? CampaignStatus::CompletedWithErrors
                : CampaignStatus::Completed;
            $changes['completed_at'] = $campaign->completed_at ?? now();
        }

        if ($changes !== []) {
            $campaign->forceFill($changes)->save();
        }

        return $campaign;
    }

    /**
     * Counts used by the campaign detail cards.
     *
     * @return array<string, int>
     */
    public function counts(Campaign $campaign): array
    {
        $counts = $campaign->recipients()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $summary = [];

        foreach (RecipientStatus::cases() as $status) {
            $summary[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $summary;
    }
}
