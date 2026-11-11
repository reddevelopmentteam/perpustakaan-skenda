<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function view(?User $user, User $target): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $user->id === $target->id;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function update(?User $user, User $target): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Staff may update student users only (field-level enforcement lives
        // in the Filament Resource slice); never staff/super-admin targets,
        // never self-update (no self role escalation).
        if ($user->isStaff()) {
            return $target->role === UserRole::STUDENT && $user->id !== $target->id;
        }

        return false;
    }

    public function delete(?User $user, User $target): bool
    {
        if ($user === null || ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->id !== $target->id;
    }

    public function restore(?User $user, User $target): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, User $target): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
