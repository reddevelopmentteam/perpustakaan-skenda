<?php

namespace App\Policies;

use App\Models\Fine;
use App\Models\User;

class FinePolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $user->isStudent();
    }

    public function view(?User $user, Fine $fine): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $fine->student_id === $user->student?->id;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, Fine $fine): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, Fine $fine): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function waive(?User $user, Fine $fine): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, Fine $fine): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, Fine $fine): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
