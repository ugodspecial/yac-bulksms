<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaign\CampaignService;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled {--limit=50}';

    protected $description = 'Queue scheduled campaigns whose send time has arrived';

    public function handle(CampaignService $campaigns): int
    {
        $due = Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $dispatched = 0;

        foreach ($due as $campaign) {
            if ($campaigns->dispatchScheduled($campaign)) {
                $dispatched++;
                $this->line(sprintf('Queued scheduled campaign #%d "%s".', $campaign->id, $campaign->name));
            } else {
                $this->warn(sprintf('Campaign #%d "%s" could not be queued; it needs review.', $campaign->id, $campaign->name));
            }
        }

        $this->info(sprintf('%d scheduled campaign(s) queued.', $dispatched));

        return self::SUCCESS;
    }
}
