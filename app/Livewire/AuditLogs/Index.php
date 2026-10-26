<?php

namespace App\Livewire\AuditLogs;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Audit logs')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $action = '';

    #[Url(as: 'user', except: '')]
    public string $userId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'userId', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($this->action !== '', fn ($query) => $query->where('action', 'like', $this->action.'%'))
            ->when($this->userId !== '', fn ($query) => $query->where('user_id', (int) $this->userId))
            ->when($this->search !== '', fn ($query) => $query->where('description', 'like', '%'.$this->search.'%'))
            ->when($this->from !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->latest()
            ->paginate(30);

        return view('livewire.audit-logs.index', [
            'logs' => $logs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
