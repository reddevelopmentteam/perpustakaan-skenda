<?php

namespace App\Enums;

enum BookCopyStatus: string
{
    case AVAILABLE = 'available';
    case BORROWED = 'borrowed';
    case RESERVED = 'reserved';
    case LOST = 'lost';
    case DAMAGED = 'damaged';
    case MAINTENANCE = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Tersedia',
            self::BORROWED => 'Dipinjam',
            self::RESERVED => 'Direservasi',
            self::LOST => 'Hilang',
            self::DAMAGED => 'Rusak',
            self::MAINTENANCE => 'Perawatan',
        };
    }

    public function isBorrowable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
