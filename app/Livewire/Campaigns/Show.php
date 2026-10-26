<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Exceptions\CampaignPreflightException;
use App\Exceptions\CampaignStateException;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignProgress;
use App\Services\Campaign\CampaignService;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Campaign')]
class Show extends Component
{
    use WithPagination;

    public Campaign $campaign;

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'phone', except: '')]
    public string $phone = '';

    public bool $showCancel = false;

    public bool $showRetry = false;

    public bool $showConfirm = false;

    public string $cancelReason = '';

    public function mount(Campaign $campaign): void
    {
        Gate::authorize('view', $campaign);

        $this->campaign = $campaign;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPhone(): void
    {
        $this->resetPage();
    }

    public function openCancel(): void
    {
        Gate::authorize('cancel', $this->campaign);

        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function cancel(CampaignService $campaigns): void
    {
        Gate::authorize('cancel', $this->campaign);

        try {
            $campaigns->cancel($this->campaign, auth()->user(), $this->cancelReason);

            $this->showCancel = false;
            $this->campaign->refresh();
            $this->dispatch('notify', message: 'Campaign cancelled. Nothing further will be sent.', type: 'success');
        } catch (CampaignStateException $e) {
            $this->showCancel = false;
            $this->addError('cancelReason', $e->getMessage());
        }
    }

    public function retryFailed(CampaignService $campaigns): void
    {
        Gate::authorize('retry', $this->campaign);

        try {
            $count = $campaigns->retryFailed($this->campaign, auth()->user());

            $this->showRetry = false;
            $this->campaign->refresh();
            $this->dispatch('notify', message: sprintf('%d failed message(s) re-queued.', $count), type: 'success');
        } catch (CampaignStateException|CampaignPreflightException $e) {
            $this->showRetry = false;
            $this->addError('retry', $e->getMessage());
        }
    }

    public function openConfirm(): void
    {
        Gate::authorize('confirm', $this->campaign);

        $this->showConfirm = true;
    }

    public function confirmCampaign(CampaignService $campaigns)
    {
        Gate::authorize('confirm', $this->campaign);

        try {
            $campaign = $campaigns->confirm($this->campaign, auth()->user());
        } catch (CampaignStateException|CampaignPreflightException $e) {
            $this->showConfirm = false;
            $this->addError('confirm', $e->getMessage());

            return null;
        }

        $this->showConfirm = false;
        $this->campaign->refresh();

        $this->dispatch(
            'notify',
            message: $campaign->status === CampaignStatus::Scheduled
                ? 'Campaign scheduled.'
                : 'Campaign queued for sending.',
            type: 'success',
        );

        return null;
    }

    /**
     * Ask Termii for the delivery report of a single recipient. Only messages
     * with a provider message id can be reconciled.
     */
    public function reconcileRecipient(int $recipientId, TermiiGatewayInterface $gateway, CampaignProgress $progress): void
    {
        Gate::authorize('retry', $this->campaign);

        $recipient = CampaignRecipient::query()
            ->where('campaign_id', $this->campaign->id)
            ->findOrFail($recipientId);

        if ($recipient->provider_message_id === null) {
            $this->addError('reconcile', 'This message has no provider message ID, so its status cannot be checked automatically. Verify it in the Termii dashboard.');

            return;
        }

        $result = $gateway->messageReport($recipient->provider_message_id);

        if (! $result->ok || $result->state === null) {
            $recipient->forceFill(['last_reconciled_at' => now()])->save();
            $this->addError('reconcile', $result->safeMessage());

            return;
        }

        $attributes = [
            'provider_status' => Str::limit((string) ($result->providerStatus ?? $result->state->value), 60, ''),
            'last_reconciled_at' => now(),
        ];

        if ($result->state->isTerminal()) {
            $status = $result->state->recipientStatus();
            $attributes['status'] = $status;

            if ($status === RecipientStatus::Delivered) {
                $attributes['delivered_at'] = now();
                $attributes['error_code'] = null;
                $attributes['error_message'] = null;
            } else {
                $attributes['failed_at'] = now();
                $attributes['error_code'] = 'delivery_failed';
                $attributes['error_message'] = Str::limit('Termii reported: '.($result->providerStatus ?? 'failed'), 190, '');
            }
        }

        $recipient->forceFill($attributes)->save();
        $progress->refresh($this->campaign);
        $this->campaign->refresh();

        $this->dispatch('notify', message: 'Recipient status refreshed from Termii.', type: 'success');
    }

    public function render(): View
    {
        $counts = app(CampaignProgress::class)->counts($this->campaign);

        $recipients = $this->campaign->recipients()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->phone !== '', fn ($query) => $query->where('phone', 'like', '%'.preg_replace('/\D+/', '', $this->phone).'%'))
            ->orderBy('id')
            ->paginate(20);

        return view('livewire.campaigns.show', [
            'counts' => $counts,
            'recipients' => $recipients,
            'statuses' => RecipientStatus::cases(),
            'audit' => \App\Models\AuditLog::query()
                ->with('user:id,name')
                ->where('subject_type', Campaign::class)
                ->where('subject_id', $this->campaign->id)
                ->latest()
                ->limit(12)
                ->get(),
            'canRetry' => Gate::allows('retry', $this->campaign)
                && $this->campaign->status->isTerminal(),
            'retryableFailures' => $this->campaign->recipients()
                ->where('status', RecipientStatus::Failed->value)
                ->whereIn('error_code', ['rate_limited', 'server_error', 'network_error'])
                ->count(),
        ]);
    }
}
