<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ContactGroup;
use App\Models\User;

class ContactGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ContactGroup $group): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function update(User $user, ContactGroup $group): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function delete(User $user, ContactGroup $group): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }
}
