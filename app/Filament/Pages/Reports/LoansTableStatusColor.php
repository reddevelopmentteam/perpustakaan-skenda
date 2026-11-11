<?php

namespace App\Filament\Pages\Reports;

use App\Enums\LoanStatus;

/**
 * Warna badge status pinjaman — cerminan `LoansTable::statusColor()`
 * agar laporan konsisten dengan resource operasional.
 */
class LoansTableStatusColor
{
    public static function color(LoanStatus|string $state): string
    {
        $status = $state instanceof LoanStatus ? $state : LoanStatus::tryFrom((string) $state);

        return match ($status) {
            LoanStatus::PENDING => 'warning',
            LoanStatus::APPROVED => 'success',
            LoanStatus::BORROWED => 'info',
            LoanStatus::PARTIALLY_RETURNED => 'info',
            LoanStatus::RETURNED => 'success',
            LoanStatus::REJECTED => 'danger',
            LoanStatus::OVERDUE => 'danger',
            LoanStatus::CANCELLED => 'gray',
            default => 'gray',
        };
    }
}
