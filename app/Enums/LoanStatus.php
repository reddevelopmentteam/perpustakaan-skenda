<?php

namespace App\Enums;

enum LoanStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case BORROWED = 'borrowed';
    case PARTIALLY_RETURNED = 'partially_returned';
    case RETURNED = 'returned';
    case REJECTED = 'rejected';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::BORROWED => 'Sedang Dipinjam',
            self::PARTIALLY_RETURNED => 'Sebagian Dikembalikan',
            self::RETURNED => 'Dikembalikan',
            self::REJECTED => 'Ditolak',
            self::OVERDUE => 'Terlambat',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::APPROVED,
            self::BORROWED,
            self::PARTIALLY_RETURNED,
            self::OVERDUE => true,
            default => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::BORROWED, self::CANCELLED],
            self::BORROWED => [self::PARTIALLY_RETURNED, self::RETURNED, self::OVERDUE],
            self::PARTIALLY_RETURNED => [self::RETURNED, self::OVERDUE],
            self::OVERDUE => [self::PARTIALLY_RETURNED, self::RETURNED],
            self::REJECTED, self::RETURNED, self::CANCELLED => [],
        };
    }
}
