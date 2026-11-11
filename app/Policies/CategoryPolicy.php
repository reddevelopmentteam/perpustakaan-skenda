<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Category $category): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, Category $category): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, Category $category): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, Category $category): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function forceDelete(?User $user, Category $category): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }
}
