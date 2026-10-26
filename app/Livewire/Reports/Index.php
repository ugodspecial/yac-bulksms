<?php

namespace App\Livewire\Reports;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Delivery reports')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $campaign = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'phone', except: '')]
    public string $phone = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['campaign', 'status', 'phone', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $base = CampaignRecipient::query();

        $summary = [
            'submitted' => (clone $base)->whereNotNull('submitted_at')->count(),
            'accepted' => (clone $base)->where('status', RecipientStatus::Accepted->value)->count(),
            'delivered' => (clone $base)->where('status', RecipientStatus::Delivered->value)->count(),
            'failed' => (clone $base)->where('status', RecipientStatus::Failed->value)->count(),
            'pending' => (clone $base)->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Processing->value])->count(),
            'unknown' => (clone $base)->where('status', RecipientStatus::Unknown->value)->count(),
        ];

        $recipients = CampaignRecipient::query()
            ->with('campaign:id,name,status')
            ->when($this->campaign !== '', fn ($query) => $query->where('campaign_id', (int) $this->campaign))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->phone !== '', fn ($query) => $query->where('phone', 'like', '%'.preg_replace('/\D+/', '', $this->phone).'%'))
            ->when($this->from !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->latest('id')
            ->paginate(25);

        return view('livewire.reports.index', [
            'recipients' => $recipients,
            'summary' => $summary,
            'statuses' => RecipientStatus::cases(),
            'campaigns' => Campaign::query()->latest()->limit(100)->get(['id', 'name']),
            'deliveryReporting' => (bool) config('termii.webhook.enabled'),
        ]);
    }
}
