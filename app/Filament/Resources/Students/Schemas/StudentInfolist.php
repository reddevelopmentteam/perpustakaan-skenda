<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentStatus;
use App\Models\Student;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StudentInfolist
{
    public static function activeLoans(Student $record): int
    {
        return $record->loans()
            ->whereIn('status', [LoanStatus::APPROVED, LoanStatus::BORROWED, LoanStatus::PARTIALLY_RETURNED, LoanStatus::OVERDUE])
            ->count();
    }

    public static function overdueLoans(Student $record): int
    {
        return $record->loans()->where('status', LoanStatus::OVERDUE)->count();
    }

    public static function unpaidFines(Student $record): int
    {
        return (int) $record->fines()->where('status', FineStatus::UNPAID)->sum('amount');
    }

    public static function activeReservations(Student $record): int
    {
        return $record->reservations()
            ->whereIn('status', [ReservationStatus::WAITING, ReservationStatus::AVAILABLE])
            ->count();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Identitas Anggota')
                    ->icon('heroicon-m-identification')
                    ->compact()
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('student_number')->label('NIS'),
                        TextEntry::make('name')->label('Nama Lengkap'),
                        TextEntry::make('class')->label('Kelas')->badge(),
                        TextEntry::make('major')->label('Jurusan')->placeholder('-'),
                        TextEntry::make('phone')->label('Telepon')->placeholder('-')->copyable(),
                        TextEntry::make('email')->label('Email')->placeholder('-')->copyable(),
                        TextEntry::make('joined_at')->label('Tanggal Bergabung')->date()->placeholder('-'),
                        TextEntry::make('created_at')->label('Terdaftar')->dateTime()->placeholder('-'),
                        TextEntry::make('address')->label('Alamat')->placeholder('-')->columnSpanFull(),
                    ]),

                Section::make('Statistik')
                    ->icon('heroicon-m-chart-bar')
                    ->compact()
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('statistik_pinjaman_aktif')
                            ->label('Pinjaman Aktif')
                            ->badge()
                            ->color('info')
                            ->state(fn (Student $record): int => self::activeLoans($record)),
                        TextEntry::make('statistik_terlambat')
                            ->label('Buku Terlambat')
                            ->badge()
                            ->color('danger')
                            ->state(fn (Student $record): int => self::overdueLoans($record)),
                        TextEntry::make('statistik_denda')
                            ->label('Denda Belum Dibayar')
                            ->badge()
                            ->color('warning')
                            ->money('IDR')
                            ->state(fn (Student $record): int => self::unpaidFines($record)),
                        TextEntry::make('statistik_reservasi')
                            ->label('Reservasi Aktif')
                            ->badge()
                            ->color('success')
                            ->state(fn (Student $record): int => self::activeReservations($record)),
                    ]),

                Section::make('Buku Sedang Dipinjam')
                    ->icon('heroicon-m-book-open')
                    ->compact()
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('pinjaman_aktif')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Nomor Pinjaman'),
                                TableColumn::make('Judul Buku'),
                                TableColumn::make('Jatuh Tempo'),
                            ])
                            ->schema([
                                TextEntry::make('loan_number'),
                                TextEntry::make('judul_buku'),
                                TextEntry::make('due_at')
                                    ->date()
                                    ->placeholder('-')
                                    ->color(fn ($state): string => ($state !== null && $state->isPast()) ? 'danger' : 'gray'),
                            ])
                            ->state(fn (Student $record): array => $record->loans()
                                ->whereIn('status', [LoanStatus::APPROVED, LoanStatus::BORROWED, LoanStatus::PARTIALLY_RETURNED, LoanStatus::OVERDUE])
                                ->with('items.book')
                                ->latest()
                                ->get()
                                ->flatMap(fn ($loan): array => $loan->items->map(fn ($item): array => [
                                    'loan_number' => $loan->loan_number,
                                    'judul_buku' => $item->book?->title ?? '-',
                                    'due_at' => $item->due_at ?? $loan->due_at,
                                ])->all())
                                ->all())
                            ->placeholder('Tidak ada buku yang sedang dipinjam.'),
                    ]),

                Section::make('Status & Akun Login')
                    ->icon('heroicon-m-shield-check')
                    ->compact()
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof StudentStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => match ($state) {
                                StudentStatus::ACTIVE => 'success',
                                StudentStatus::BLOCKED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('user.name')->label('Akun Login')->placeholder('Belum punya akun'),
                        TextEntry::make('user.email')->label('Email Akun')->placeholder('-')->copyable(),
                        TextEntry::make('user.last_login_at')->label('Login Terakhir')->dateTime()->placeholder('-'),
                    ]),

                Section::make('Riwayat Peminjaman (10 Terakhir)')
                    ->icon('heroicon-m-clock')
                    ->compact()
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('riwayat_peminjaman')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Nomor'),
                                TableColumn::make('Status'),
                                TableColumn::make('Dipinjam'),
                                TableColumn::make('Jatuh Tempo'),
                            ])
                            ->schema([
                                TextEntry::make('loan_number'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                                    ->color(fn ($state): string => match ($state) {
                                        LoanStatus::APPROVED => 'info',
                                        LoanStatus::BORROWED => 'warning',
                                        LoanStatus::PARTIALLY_RETURNED => 'primary',
                                        LoanStatus::OVERDUE => 'danger',
                                        LoanStatus::RETURNED => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('borrowed_at')->date()->placeholder('-'),
                                TextEntry::make('due_at')->date()->placeholder('-'),
                            ])
                            ->state(fn (Student $record): array => $record->loans()
                                ->latest()
                                ->limit(10)
                                ->get()
                                ->map(fn ($loan): array => [
                                    'loan_number' => $loan->loan_number,
                                    'status' => $loan->status,
                                    'borrowed_at' => $loan->borrowed_at,
                                    'due_at' => $loan->due_at,
                                ])
                                ->all())
                            ->placeholder('Belum ada riwayat peminjaman.'),
                    ]),

                Section::make('Riwayat Denda')
                    ->icon('heroicon-m-banknotes')
                    ->compact()
                    ->columnSpan(1)
                    ->schema([
                        RepeatableEntry::make('riwayat_denda')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Jumlah'),
                                TableColumn::make('Status'),
                                TableColumn::make('Tanggal'),
                            ])
                            ->schema([
                                TextEntry::make('amount')->money('IDR'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof FineStatus ? $state->label() : (string) $state)
                                    ->color(fn ($state): string => match ($state) {
                                        FineStatus::UNPAID => 'warning',
                                        FineStatus::PAID => 'success',
                                        default => 'gray',
                                    }),
                                TextEntry::make('created_at')->date()->placeholder('-'),
                            ])
                            ->state(fn (Student $record): array => $record->fines()
                                ->latest()
                                ->get()
                                ->map(fn ($fine): array => [
                                    'amount' => $fine->amount,
                                    'status' => $fine->status,
                                    'created_at' => $fine->created_at,
                                ])
                                ->all())
                            ->placeholder('Tidak ada riwayat denda.'),
                    ]),
            ]);
    }
}