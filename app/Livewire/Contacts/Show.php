<?php

namespace App\Livewire\Contacts;

use App\Enums\RecipientStatus;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CampaignRecipient;
use App\Services\Contacts\ContactService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Contact')]
class Show extends Component
{
    public Contact $contact;

    public string $reason = '';

    public function mount(Contact $contact): void
    {
        Gate::authorize('view', $contact);

        $this->contact = $contact;
    }

    public function unsubscribe(ContactService $contacts): void
    {
        Gate::authorize('unsubscribe', $this->contact);

        $this->validate(['reason' => ['required', 'string', 'max:191']]);

        $contacts->unsubscribe($this->contact, auth()->user(), $this->reason);

        $this->contact->refresh();
        $this->reason = '';

        $this->dispatch('notify', message: 'Contact unsubscribed and suppressed.', type: 'success');
    }

    public function resubscribe(ContactService $contacts): void
    {
        Gate::authorize('admin');

        $contacts->resubscribe($this->contact, auth()->user());

        $this->contact->refresh();

        $this->dispatch('notify', message: 'Contact re-subscribed.', type: 'success');
    }

    public function render(): View
    {
        $recipients = CampaignRecipient::query()
            ->with('campaign:id,name,status')
            ->where('contact_id', $this->contact->id)
            ->latest('id')
            ->limit(10)
            ->get();

        $summary = [
            'messages' => CampaignRecipient::query()->where('contact_id', $this->contact->id)->whereNotNull('submitted_at')->count(),
            'delivered' => CampaignRecipient::query()->where('contact_id', $this->contact->id)->where('status', RecipientStatus::Delivered->value)->count(),
            'failed' => CampaignRecipient::query()->where('contact_id', $this->contact->id)->where('status', RecipientStatus::Failed->value)->count(),
        ];

        $audit = AuditLog::query()
            ->with('user:id,name')
            ->where('subject_type', Contact::class)
            ->where('subject_id', $this->contact->id)
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.contacts.show', [
            'groups' => $this->contact->groups()->orderBy('name')->get(),
            'recipients' => $recipients,
            'summary' => $summary,
            'audit' => $audit,
        ]);
    }
}
