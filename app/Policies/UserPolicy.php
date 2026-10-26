<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $target): bool
    {
        if (! $user->isAdmin() || $user->is($target)) {
            return false;
        }

        // Never delete the last active administrator.
        if ($target->role === UserRole::Admin && $target->is_active) {
            return User::query()
                ->where('role', UserRole::Admin->value)
                ->where('is_active', true)
                ->whereKeyNot($target->getKey())
                ->exists();
        }

        return true;
    }
}
