<?php

namespace App\Livewire\Groups;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Group')]
class Show extends Component
{
    use WithPagination;

    public ContactGroup $group;

    public string $search = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    public bool $showAdd = false;

    public string $addSearch = '';

    /** @var array<int, int|string> */
    public array $addSelected = [];

    public function mount(ContactGroup $group): void
    {
        Gate::authorize('view', $group);

        $this->group = $group;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openAdd(): void
    {
        Gate::authorize('update', $this->group);

        $this->addSearch = '';
        $this->addSelected = [];
        $this->showAdd = true;
    }

    public function addContacts(AuditLogger $audit): void
    {
        Gate::authorize('update', $this->group);

        $ids = array_map('intval', $this->addSelected);

        if ($ids === []) {
            $this->addError('addSelected', 'Select at least one contact to add.');

            return;
        }

        $this->group->contacts()->syncWithoutDetaching($ids);

        $audit->log('group.contacts_added', $this->group, sprintf(
            'Added %d contact(s) to group "%s".',
            count($ids),
            $this->group->name,
        ));

        $this->showAdd = false;
        $this->addSelected = [];
        $this->dispatch('notify', message: 'Contacts added to the group.', type: 'success');
    }

    public function removeSelected(AuditLogger $audit): void
    {
        Gate::authorize('update', $this->group);

        $ids = array_map('intval', $this->selected);

        if ($ids === []) {
            $this->addError('selected', 'Select at least one contact to remove.');

            return;
        }

        $removed = $this->group->contacts()->detach($ids);

        $audit->log('group.contacts_removed', $this->group, sprintf(
            'Removed %d contact(s) from group "%s".',
            $removed,
            $this->group->name,
        ));

        $this->selected = [];
        $this->dispatch('notify', message: sprintf('%d contact(s) removed from the group.', $removed), type: 'success');
    }

    public function render(): View
    {
        $contacts = $this->group->contacts()
            ->search($this->search)
            ->orderByRaw('coalesce(name, phone) asc')
            ->paginate(15);

        $candidates = $this->showAdd
            ? Contact::query()
                ->search($this->addSearch)
                ->whereNotIn('id', $this->group->contacts()->select('contacts.id'))
                ->orderByRaw('coalesce(name, phone) asc')
                ->limit(25)
                ->get(['id', 'name', 'phone'])
            : collect();

        $subscribedCount = $this->group->subscribedContacts()->count();

        return view('livewire.groups.show', [
            'contacts' => $contacts,
            'candidates' => $candidates,
            'totalCount' => $this->group->contacts()->count(),
            'subscribedCount' => $subscribedCount,
        ]);
    }
}
