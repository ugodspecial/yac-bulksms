<?php

namespace App\Livewire\Contacts;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\Contacts\ContactService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Contacts')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $group = '';

    #[Url(except: '')]
    public string $status = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public bool $showDelete = false;

    public bool $showUnsubscribe = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public ?int $unsubscribingId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $unsubscribeReason = '';

    /** @var array<string, string> */
    public array $custom = [];

    public function mount(): void
    {
        $this->custom = array_fill_keys(settings()->customFields(), '');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Contact::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);

        Gate::authorize('update', $contact);

        $this->editingId = $contact->id;
        $this->name = (string) $contact->name;
        $this->phone = (string) $contact->phone;
        $this->email = (string) $contact->email;
        $this->custom = array_merge(
            array_fill_keys(settings()->customFields(), ''),
            array_intersect_key($contact->custom_fields ?? [], array_flip(settings()->customFields())),
        );
        $this->showForm = true;
    }

    public function save(ContactService $contacts): void
    {
        $this->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $payload = [
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'custom_fields' => array_filter($this->custom, fn ($value) => trim((string) $value) !== ''),
        ];

        try {
            if ($this->editingId !== null) {
                $contact = Contact::query()->findOrFail($this->editingId);
                Gate::authorize('update', $contact);
                $contacts->update($contact, $payload, auth()->user());
                $message = 'Contact updated.';
            } else {
                Gate::authorize('create', Contact::class);
                $contact = $contacts->create($payload, auth()->user());
                $message = $contact->isSubscribed()
                    ? 'Contact created.'
                    : 'Contact created, but this number is suppressed. It will never receive campaigns.';
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('phone', $message);
                }
            }

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', message: $message, type: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        Gate::authorize('delete', $contact);

        $this->deletingId = $contact->id;
        $this->showDelete = true;
    }

    public function delete(ContactService $contacts): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $contact = Contact::query()->findOrFail($this->deletingId);
        Gate::authorize('delete', $contact);

        $contacts->delete($contact, auth()->user());

        $this->showDelete = false;
        $this->deletingId = null;
        $this->dispatch('notify', message: 'Contact deleted.', type: 'success');
    }

    public function startUnsubscribe(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);
        Gate::authorize('unsubscribe', $contact);

        $this->unsubscribingId = $contact->id;
        $this->unsubscribeReason = 'Unsubscribed by an administrator.';
        $this->showUnsubscribe = true;
    }

    public function confirmUnsubscribe(ContactService $contacts): void
    {
        if ($this->unsubscribingId === null) {
            return;
        }

        $this->validate(['unsubscribeReason' => ['required', 'string', 'max:191']]);

        $contact = Contact::query()->findOrFail($this->unsubscribingId);
        Gate::authorize('unsubscribe', $contact);

        $contacts->unsubscribe($contact, auth()->user(), $this->unsubscribeReason);

        $this->showUnsubscribe = false;
        $this->unsubscribingId = null;
        $this->dispatch('notify', message: 'Contact unsubscribed and added to the suppression list.', type: 'success');
    }

    public function resubscribe(int $id, ContactService $contacts): void
    {
        Gate::authorize('admin');

        $contact = Contact::query()->findOrFail($id);

        $contacts->resubscribe($contact, auth()->user());

        $this->dispatch('notify', message: 'Contact re-subscribed and removed from the suppression list.', type: 'success');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function render(): View
    {
        $contacts = Contact::query()
            ->with('groups:id,name')
            ->search($this->search)
            ->when($this->group !== '', fn ($query) => $query->whereHas(
                'groups',
                fn ($groupQuery) => $groupQuery->where('contact_groups.id', (int) $this->group),
            ))
            ->when($this->status !== '', fn ($query) => $query->where('subscription_status', $this->status))
            ->orderByRaw('coalesce(name, phone) asc')
            ->paginate($this->perPage);

        return view('livewire.contacts.index', [
            'contacts' => $contacts,
            'groups' => ContactGroup::query()->orderBy('name')->get(['id', 'name']),
            'configuredCustomFields' => settings()->customFields(),
            'subscribedTotal' => Contact::query()->where('subscription_status', 'subscribed')->count(),
        ]);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->phone = '';
        $this->email = '';
        $this->custom = array_fill_keys(settings()->customFields(), '');
        $this->resetErrorBag();
    }
}
