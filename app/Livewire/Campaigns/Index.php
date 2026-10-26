<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('SMS campaigns')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showDelete = false;

    public ?int $deletingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $campaign = Campaign::query()->findOrFail($id);
        Gate::authorize('delete', $campaign);

        $this->deletingId = $campaign->id;
        $this->showDelete = true;
    }

    public function delete(CampaignService $campaigns, AuditLogger $audit): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $campaign = Campaign::query()->findOrFail($this->deletingId);
        Gate::authorize('delete', $campaign);

        $campaigns->delete($campaign, auth()->user());

        $this->showDelete = false;
        $this->deletingId = null;
        $this->dispatch('notify', message: 'Draft campaign deleted.', type: 'success');
    }

    public function render(): View
    {
        $campaigns = Campaign::query()
            ->with('creator:id,name')
            ->withCount([
                'recipients as delivered_count' => fn ($query) => $query->where('status', RecipientStatus::Delivered->value),
                'recipients as accepted_count' => fn ($query) => $query->where('status', RecipientStatus::Accepted->value),
                'recipients as failed_count' => fn ($query) => $query->where('status', RecipientStatus::Failed->value),
            ])
            ->search($this->search)
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest()
            ->paginate(15);

        $statusCounts = Campaign::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return view('livewire.campaigns.index', [
            'campaigns' => $campaigns,
            'statuses' => CampaignStatus::cases(),
            'statusCounts' => $statusCounts,
        ]);
    }
}
