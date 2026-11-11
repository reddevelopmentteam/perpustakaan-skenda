<?php

namespace App\Policies;

use App\Models\LoanItem;
use App\Models\User;

class LoanItemPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, LoanItem $item): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isStaffLike($user)) {
            return true;
        }

        return $item->loan !== null
            && $item->loan->student_id === $user->student?->id;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, LoanItem $item): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, LoanItem $item): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, LoanItem $item): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, LoanItem $item): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
