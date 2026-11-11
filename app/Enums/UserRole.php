<?php

namespace App\Enums;

enum UserRole: string
{
    case STUDENT = 'student';
    case STAFF = 'staff';
    case SUPER_ADMIN = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::STUDENT => 'Siswa',
            self::STAFF => 'Petugas',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }
}
