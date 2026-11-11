<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

class BookPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Book $book): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, Book $book): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, Book $book): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, Book $book): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function forceDelete(?User $user, Book $book): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }
}
