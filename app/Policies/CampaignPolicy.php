<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        if (! $campaign->isEditable()) {
            return false;
        }

        return $user->isAdmin() || $campaign->created_by === $user->id;
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign) && $campaign->confirmed_at === null;
    }

    public function confirm(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::SendCampaigns);
    }

    public function cancel(User $user, Campaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function retry(User $user, Campaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ExportReports);
    }
}
