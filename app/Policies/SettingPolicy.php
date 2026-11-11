<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function view(?User $user, Setting $setting): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function create(?User $user): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function update(?User $user, Setting $setting): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function delete(?User $user, Setting $setting): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function restore(?User $user, Setting $setting): bool
    {
        return false;
    }

    public function forceDelete(?User $user, Setting $setting): bool
    {
        return false;
    }
}
