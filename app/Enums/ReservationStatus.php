<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case WAITING = 'waiting';
    case AVAILABLE = 'available';
    case FULFILLED = 'fulfilled';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::WAITING => 'Menunggu Antrean',
            self::AVAILABLE => 'Siap Diambil',
            self::FULFILLED => 'Selesai',
            self::EXPIRED => 'Kedaluwarsa',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isActive(): bool
    {
        return $this === self::WAITING || $this === self::AVAILABLE;
    }
}
