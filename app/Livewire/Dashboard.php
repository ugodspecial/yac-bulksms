<?php

namespace App\Livewire;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ProviderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): View
    {
        $totals = [
            'contacts' => Contact::query()->count(),
            'subscribed' => Contact::query()->where('subscription_status', 'subscribed')->count(),
            'groups' => ContactGroup::query()->count(),
            'campaigns' => Campaign::query()->count(),
            'submitted' => CampaignRecipient::query()->whereNotNull('submitted_at')->count(),
            'delivered' => CampaignRecipient::query()->where('status', RecipientStatus::Delivered->value)->count(),
            'failed' => CampaignRecipient::query()->where('status', RecipientStatus::Failed->value)->count(),
            'in_flight' => CampaignRecipient::query()->whereIn('status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Processing->value,
            ])->count(),
            'accepted' => CampaignRecipient::query()->where('status', RecipientStatus::Accepted->value)->count(),
            'unknown' => CampaignRecipient::query()->where('status', RecipientStatus::Unknown->value)->count(),
        ];

        $provider = ProviderStatus::termii();

        $recentCampaigns = Campaign::query()
            ->withCount([
                'recipients as delivered_count' => fn ($query) => $query->where('status', RecipientStatus::Delivered->value),
                'recipients as failed_count' => fn ($query) => $query->where('status', RecipientStatus::Failed->value),
                'recipients as accepted_count' => fn ($query) => $query->where('status', RecipientStatus::Accepted->value),
            ])
            ->latest()
            ->limit(5)
            ->get();

        $recentFailures = CampaignRecipient::query()
            ->with('campaign:id,name')
            ->where('status', RecipientStatus::Failed->value)
            ->latest('failed_at')
            ->limit(5)
            ->get();

        $activity = $this->activity();

        $deliveryReporting = (bool) config('termii.webhook.enabled')
            || CampaignRecipient::query()->whereNotNull('provider_message_id')->exists();

        $activeCampaigns = Campaign::query()->whereIn('status', [
            CampaignStatus::Queued->value,
            CampaignStatus::Processing->value,
            CampaignStatus::Scheduled->value,
        ])->count();

        return view('livewire.dashboard', compact(
            'totals',
            'provider',
            'recentCampaigns',
            'recentFailures',
            'activity',
            'deliveryReporting',
            'activeCampaigns',
        ));
    }

    /**
     * @return Collection<int, array{date: string, label: string, campaigns: int, messages: int}>
     */
    private function activity(): Collection
    {
        $days = collect();

        foreach (range(13, 0) as $offset) {
            $day = Carbon::today()->subDays($offset);

            $days->push([
                'date' => $day->toDateString(),
                'label' => $day->format('D j M'),
                'campaigns' => Campaign::query()->whereDate('created_at', $day->toDateString())->count(),
                'messages' => CampaignRecipient::query()->whereDate('submitted_at', $day->toDateString())->count(),
            ]);
        }

        return $days;
    }
}
