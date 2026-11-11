<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
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

    public function view(?User $user, Student $student): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $user->student?->id === $student->id;
    }

    public function create(?User $user): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function update(?User $user, Student $student): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function delete(?User $user, Student $student): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function restore(?User $user, Student $student): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, Student $student): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
