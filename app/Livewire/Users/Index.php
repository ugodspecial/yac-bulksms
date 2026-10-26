<?php

namespace App\Livewire\Users;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('User management')]
class Index extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public bool $showDelete = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'operator';

    /** @var array<int, string> */
    public array $permissions = [];

    public bool $isActive = true;

    public bool $mustChangePassword = true;

    public string $password = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function openCreate(): void
    {
        Gate::authorize('create', User::class);

        $this->resetForm();
        $this->role = UserRole::Operator->value;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        Gate::authorize('update', $user);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;
        $this->permissions = array_values(array_intersect($user->permissions ?? [], Permission::values()));
        $this->isActive = $user->is_active;
        $this->mustChangePassword = $user->must_change_password;
        $this->password = '';
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingId !== null ? User::query()->findOrFail($this->editingId) : null;

        Gate::authorize($editing !== null ? 'update' : 'create', $editing ?? User::class);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($this->editingId),
            ],
            'role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::values())],
            'password' => [$editing === null ? 'required' : 'nullable', 'string', Password::min(10)->letters()->numbers()],
        ]);

        $role = UserRole::from($this->role);

        if ($editing !== null && $editing->role === UserRole::Admin && $role !== UserRole::Admin) {
            if ($this->activeAdminCount(except: $editing->id) === 0) {
                $this->addError('role', 'At least one active administrator must remain.');

                return;
            }
        }

        if ($editing !== null && $editing->role === UserRole::Admin && ! $this->isActive) {
            if ($this->activeAdminCount(except: $editing->id) === 0) {
                $this->addError('isActive', 'At least one active administrator must remain.');

                return;
            }
        }

        if ($editing !== null && $editing->is(auth()->user()) && ! $this->isActive) {
            $this->addError('isActive', 'You cannot deactivate your own account.');

            return;
        }

        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role,
            'permissions' => $role === UserRole::Admin ? [] : array_values(array_intersect($this->permissions, Permission::values())),
            'is_active' => $this->isActive,
            'must_change_password' => $this->mustChangePassword,
        ];

        if ($this->password !== '') {
            $attributes['password'] = $this->password;
            $attributes['must_change_password'] = true;
        }

        if ($editing !== null) {
            $editing->forceFill($attributes)->save();
            $action = 'user.updated';
            $description = sprintf('Updated the account for %s.', $editing->name);
        } else {
            $user = User::create($attributes);
            $action = 'user.created';
            $description = sprintf('Created the account for %s.', $user->name);
        }

        $audit->log($action, $editing ?? $user, $description, [
            'role' => $role->value,
            'is_active' => $this->isActive,
            'permissions' => $attributes['permissions'],
        ]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', message: 'User account saved.', type: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $user = User::query()->findOrFail($id);
        Gate::authorize('delete', $user);

        $this->deletingId = $user->id;
        $this->showDelete = true;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $user = User::query()->findOrFail($this->deletingId);
        Gate::authorize('delete', $user);

        $name = $user->name;
        $user->delete();

        $audit->log('user.deleted', null, sprintf('Deleted the account for %s.', $name));

        $this->showDelete = false;
        $this->deletingId = null;
        $this->dispatch('notify', message: 'User account deleted.', type: 'success');
    }

    public function render(): View
    {
        return view('livewire.users.index', [
            'users' => User::query()->orderBy('name')->paginate(20),
            'roles' => UserRole::options(),
            'permissionOptions' => Permission::cases(),
        ]);
    }

    private function activeAdminCount(?int $except = null): int
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->count();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->role = UserRole::Operator->value;
        $this->permissions = [];
        $this->isActive = true;
        $this->mustChangePassword = true;
        $this->password = '';
        $this->resetErrorBag();
    }
}
