<?php

namespace App\Enums;

enum FineStatus: string
{
    case UNPAID = 'unpaid';
    case PAID = 'paid';
    case WAIVED = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Belum Dibayar',
            self::PAID => 'Lunas',
            self::WAIVED => 'Dibebaskan',
        };
    }
}
