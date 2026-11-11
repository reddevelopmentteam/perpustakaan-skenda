<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    private function isStaffLike(User $user): bool
    {
        return $user->isStaff() || $user->isSuperAdmin();
    }

    public function viewAny(?User $user): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function view(?User $user, AuditLog $auditLog): bool
    {
        return $user !== null && $user->isSuperAdmin();
    }

    public function create(?User $user): bool
    {
        return false;
    }

    public function update(?User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(?User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function restore(?User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function forceDelete(?User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
