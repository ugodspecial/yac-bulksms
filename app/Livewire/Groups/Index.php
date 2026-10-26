<?php

namespace App\Livewire\Groups;

use App\Models\ContactGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Contact groups')]
class Index extends Component
{
    public bool $showForm = false;

    public bool $showDelete = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $description = '';

    public function openCreate(): void
    {
        Gate::authorize('create', ContactGroup::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $group = ContactGroup::query()->findOrFail($id);
        Gate::authorize('update', $group);

        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->description = (string) $group->description;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('contact_groups', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingId !== null) {
            $group = ContactGroup::query()->findOrFail($this->editingId);
            Gate::authorize('update', $group);

            $group->update(['name' => $this->name, 'description' => $this->description ?: null]);
            $audit->log('group.updated', $group, sprintf('Renamed group to "%s".', $group->name));
        } else {
            Gate::authorize('create', ContactGroup::class);

            $group = ContactGroup::create([
                'name' => $this->name,
                'description' => $this->description ?: null,
                'created_by' => auth()->id(),
            ]);
            $audit->log('group.created', $group, sprintf('Created group "%s".', $group->name));
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', message: 'Contact group saved.', type: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $group = ContactGroup::query()->findOrFail($id);
        Gate::authorize('delete', $group);

        $this->deletingId = $group->id;
        $this->showDelete = true;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $group = ContactGroup::query()->findOrFail($this->deletingId);
        Gate::authorize('delete', $group);

        $name = $group->name;
        $group->contacts()->detach();
        $group->delete();

        $audit->log('group.deleted', null, sprintf('Deleted group "%s".', $name));

        $this->showDelete = false;
        $this->deletingId = null;
        $this->dispatch('notify', message: 'Contact group deleted. Contacts were kept.', type: 'success');
    }

    public function render(): View
    {
        $groups = ContactGroup::query()
            ->withCount([
                'contacts',
                'subscribedContacts as subscribed_count',
            ])
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.groups.index', [
            'groups' => $groups,
        ]);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->resetErrorBag();
    }
}
