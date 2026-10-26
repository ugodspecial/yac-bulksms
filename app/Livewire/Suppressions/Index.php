<?php

namespace App\Livewire\Suppressions;

use App\Models\SuppressionEntry;
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
#[Title('Suppression list')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public bool $showRemove = false;

    public string $phone = '';

    public string $reason = '';

    public ?int $removingId = null;

    public function mount(): void
    {
        Gate::authorize('manage-suppressions');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        Gate::authorize('manage-suppressions');

        $this->phone = '';
        $this->reason = '';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function add(ContactService $contacts): void
    {
        Gate::authorize('manage-suppressions');

        $this->validate([
            'phone' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:191'],
        ]);

        try {
            $contacts->addSuppression($this->phone, auth()->user(), $this->reason, 'manual');
        } catch (ValidationException $e) {
            $this->addError('phone', collect($e->errors())->flatten()->first());

            return;
        }

        $this->showForm = false;
        $this->reset('phone', 'reason');
        $this->dispatch('notify', message: 'Number added to the suppression list.', type: 'success');
    }

    public function confirmRemove(int $id): void
    {
        Gate::authorize('manage-suppressions');

        $this->removingId = $id;
        $this->showRemove = true;
    }

    public function remove(ContactService $contacts): void
    {
        Gate::authorize('manage-suppressions');

        if ($this->removingId === null) {
            return;
        }

        $entry = SuppressionEntry::query()->findOrFail($this->removingId);
        $contacts->removeSuppression($entry, auth()->user());

        $this->showRemove = false;
        $this->removingId = null;
        $this->dispatch('notify', message: 'Number removed from the suppression list. The contact is not automatically re-subscribed.', type: 'success');
    }

    public function render(): View
    {
        $entries = SuppressionEntry::query()
            ->with('creator:id,name')
            ->when($this->search !== '', function ($query): void {
                $digits = preg_replace('/\D+/', '', $this->search) ?? '';

                $query->where(function ($query) use ($digits): void {
                    $query->where('phone', 'like', '%'.$digits.'%')
                        ->orWhere('reason', 'like', '%'.$this->search.'%');
                });
            })
            ->latest()
            ->paginate(20);

        return view('livewire.suppressions.index', [
            'entries' => $entries,
            'total' => SuppressionEntry::query()->count(),
        ]);
    }
}
