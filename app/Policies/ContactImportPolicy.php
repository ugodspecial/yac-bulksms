<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ContactImport;
use App\Models\User;

class ContactImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ImportContacts) || $user->hasPermission(Permission::ManageContacts);
    }

    public function view(User $user, ContactImport $import): bool
    {
        return $user->isAdmin() || $import->user_id === $user->id || $user->hasPermission(Permission::ImportContacts);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ImportContacts);
    }

    public function commit(User $user, ContactImport $import): bool
    {
        return $user->hasPermission(Permission::ImportContacts) && $this->view($user, $import);
    }

    public function delete(User $user, ContactImport $import): bool
    {
        return $user->isAdmin() || ($import->user_id === $user->id && $user->hasPermission(Permission::ImportContacts));
    }
}
