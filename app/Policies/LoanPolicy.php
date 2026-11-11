<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Loan $loan): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $loan->student_id === $user->student?->id;
    }

    public function create(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || (bool) $user->student?->isBorrowable();
    }

    public function update(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, Loan $loan): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function restore(?User $user, Loan $loan): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, Loan $loan): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function approve(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function reject(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function markBorrowed(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function return(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function extend(?User $user, Loan $loan): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }
}
