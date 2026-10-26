<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function unsubscribe(User $user, Contact $contact): bool
    {
        return $user->hasPermission(Permission::ManageContacts);
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportReports);
    }
}
