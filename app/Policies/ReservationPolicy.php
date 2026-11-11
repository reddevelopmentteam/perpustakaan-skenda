<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    private function isOwner(?User $user, Reservation $reservation): bool
    {
        if ($user === null) {
            return false;
        }

        return $reservation->student_id === $user->student?->id;
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Reservation $reservation): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || $this->isOwner($user, $reservation);
    }

    public function create(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->isStaffLike($user) || (bool) $user->student?->isBorrowable();
    }

    public function update(?User $user, Reservation $reservation): bool
    {
        return $user !== null && $this->isStaffLike($user);
    }

    public function cancel(?User $user, Reservation $reservation): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isStaffLike($user)) {
            return true;
        }

        return $this->isOwner($user, $reservation) && $reservation->isActive();
    }

    public function delete(?User $user, Reservation $reservation): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function restore(?User $user, Reservation $reservation): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function forceDelete(?User $user, Reservation $reservation): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }
}
