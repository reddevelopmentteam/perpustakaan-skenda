<?php

namespace App\Enums;

enum StudentStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::INACTIVE => 'Nonaktif',
            self::BLOCKED => 'Diblokir',
        };
    }

    public function canBorrow(): bool
    {
        return $this === self::ACTIVE;
    }
}
