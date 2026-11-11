<?php

namespace App\Policies;

use App\Models\LoanExtension;
use App\Models\User;

class LoanExtensionPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    private function isOwner(?User $user, LoanExtension $extension): bool
    {
        if ($user === null || $extension->loan === null) {
            return false;
        }

        return $extension->loan->student_id === $user->student?->id;
    }

    public function viewAny(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $user->isStudent();
    }

    public function view(?User $user, LoanExtension $extension): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $this->isOwner($user, $extension);
    }

    public function create(?User $user, mixed $loan = null): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isStaffLike($user)) {
            return true;
        }

        if ((bool) $user->student?->isBorrowable() === false) {
            return false;
        }

        // When the target loan is supplied (e.g. `can('create', [LoanExtension::class, $loan])`),
        // the student must own it. Without loan context, borrowable status alone suffices
        // because ownership is re-checked at the service layer.
        if ($loan !== null) {
            return $loan instanceof \App\Models\Loan
                && $loan->student_id === $user->student?->id;
        }

        return true;
    }


    public function update(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function approve(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function reject(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function restore(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, LoanExtension $extension): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
