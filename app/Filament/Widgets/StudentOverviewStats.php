<?php

namespace App\Filament\Widgets;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Reservation;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverview;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentOverviewStats extends BaseStatsOverview
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Ringkasan Saya';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isStudent() && $user->student !== null;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $studentId = auth()->user()?->student?->id;

        if ($studentId === null) {
            return [];
        }

        $now = now();

        $active = Loan::where('student_id', $studentId)->active()->count();

        $dueSoon = Loan::where('student_id', $studentId)->active()
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->copy()->addDays(3))
            ->count();

        $overdue = Loan::where('student_id', $studentId)
            ->where('status', LoanStatus::OVERDUE)
            ->count();

        $unpaid = (int) Fine::where('student_id', $studentId)
            ->where('status', FineStatus::UNPAID)
            ->sum('amount');

        $reservations = Reservation::where('student_id', $studentId)->active()->count();

        return [
            Stat::make('Sedang Dipinjam', $active)
                ->description('Pinjaman aktif milik saya')
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('info'),
            Stat::make('Mendekati Jatuh Tempo', $dueSoon)
                ->description('Jatuh tempo dalam 3 hari')
                ->icon(Heroicon::OutlinedClock)
                ->color($dueSoon > 0 ? 'warning' : 'success'),
            Stat::make('Terlambat', $overdue)
                ->description('Pinjaman melewati jatuh tempo')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Denda Belum Dibayar', self::rupiah($unpaid))
                ->description('Total denda menunggu pembayaran')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color($unpaid > 0 ? 'danger' : 'success'),
            Stat::make('Reservasi Aktif', $reservations)
                ->description('Antrean buku milik saya')
                ->icon(Heroicon::OutlinedBookmark)
                ->color('primary'),
        ];
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
