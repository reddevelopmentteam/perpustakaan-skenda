<?php

namespace App\Enums;

enum LoanItemStatus: string
{
    case PENDING = 'pending';
    case BORROWED = 'borrowed';
    case RETURNED = 'returned';
    case OVERDUE = 'overdue';
    case LOST = 'lost';
    case DAMAGED = 'damaged';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::BORROWED => 'Dipinjam',
            self::RETURNED => 'Dikembalikan',
            self::OVERDUE => 'Terlambat',
            self::LOST => 'Hilang',
            self::DAMAGED => 'Rusak',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
