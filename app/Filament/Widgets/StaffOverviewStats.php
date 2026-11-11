<?php

namespace App\Filament\Widgets;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Enums\StudentStatus;
use App\Models\Book;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\Student;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverview;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StaffOverviewStats extends BaseStatsOverview
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Ringkasan Perpustakaan';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $unpaid = (int) Fine::where('status', FineStatus::UNPAID)->sum('amount');

        return [
            Stat::make('Total Buku', Book::count())
                ->description('Judul buku terdaftar')
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('primary'),
            Stat::make('Anggota Aktif', Student::where('status', StudentStatus::ACTIVE)->count())
                ->description('Siswa berstatus aktif')
                ->icon(Heroicon::OutlinedUsers)
                ->color('success'),
            Stat::make('Peminjaman Aktif', Loan::active()->count())
                ->description('Pinjaman belum kembali')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('info'),
            Stat::make('Menunggu Persetujuan', Loan::where('status', LoanStatus::PENDING)->count())
                ->description('Permintaan perlu diproses')
                ->icon(Heroicon::OutlinedClock)
                ->color('warning'),
            Stat::make('Terlambat', Loan::where('status', LoanStatus::OVERDUE)->count())
                ->description('Pinjaman lewat jatuh tempo')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
            Stat::make('Dikembalikan Hari Ini', Loan::whereDate('returned_at', today())->count())
                ->description('Pinjaman selesai hari ini')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make('Reservasi Aktif', Reservation::active()->count())
                ->description('Antrean menunggu dan siap ambil')
                ->icon(Heroicon::OutlinedBookmark)
                ->color('primary'),
            Stat::make('Denda Belum Dibayar', self::rupiah($unpaid))
                ->description('Total denda menunggu pembayaran')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color($unpaid > 0 ? 'danger' : 'success'),
        ];
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
