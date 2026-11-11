<?php

namespace App\Filament\Resources\Fines\Schemas;

use App\Enums\FineStatus;
use App\Filament\Resources\Fines\Tables\FinesTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FineInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Denda')
                    ->icon('heroicon-m-banknotes')
                    ->compact()
                    ->schema([
                        TextEntry::make('loan.loan_number')
                            ->label('No. Pinjaman')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('student.name')
                            ->label('Siswa'),
                        TextEntry::make('student.student_number')
                            ->label('NIS')
                            ->placeholder('-'),
                        TextEntry::make('student.class')
                            ->label('Kelas')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof FineStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => FinesTable::statusColor($state)),
                    ])
                    ->columns(3),

                Section::make('Rincian Perhitungan')
                    ->icon('heroicon-m-calculator')
                    ->compact()
                    ->schema([
                        TextEntry::make('late_days')
                            ->label('Hari Terlambat')
                            ->suffix(' hari'),
                        TextEntry::make('fine_rate_snapshot')
                            ->label('Tarif per Hari')
                            ->money('IDR')
                            ->helperText('Tarif saat dihitung; perubahan setting tidak mengubah denda ini.'),
                        TextEntry::make('amount')
                            ->label('Total Denda')
                            ->money('IDR')
                            ->weight('bold'),
                    ])
                    ->columns(3),

                Section::make('Pembayaran')
                    ->icon('heroicon-m-credit-card')
                    ->compact()
                    ->schema([
                        TextEntry::make('paid_at')
                            ->label('Dibayar Pada')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('cashier.name')
                            ->label('Kasir')
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
