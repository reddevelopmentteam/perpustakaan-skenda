<?php

namespace App\Policies;

use App\Models\BookCopy;
use App\Models\User;

class BookCopyPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, BookCopy $bookCopy): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, BookCopy $bookCopy): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, BookCopy $bookCopy): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, BookCopy $bookCopy): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function forceDelete(?User $user, BookCopy $bookCopy): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }
}
