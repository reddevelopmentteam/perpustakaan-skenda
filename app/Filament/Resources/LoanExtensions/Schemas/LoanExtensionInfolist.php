<?php

namespace App\Filament\Resources\LoanExtensions\Schemas;

use App\Enums\ExtensionStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanExtensionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pengajuan Perpanjangan')
                    ->icon('heroicon-m-arrow-path')
                    ->compact()
                    ->schema([
                        TextEntry::make('loan.loan_number')
                            ->label('Nomor Pinjaman')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('loan.student.name')
                            ->label('Siswa')
                            ->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof ExtensionStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => match ($state) {
                                ExtensionStatus::PENDING => 'warning',
                                ExtensionStatus::APPROVED => 'success',
                                ExtensionStatus::REJECTED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('old_due_at')
                            ->label('Tenggat Lama')
                            ->date('d M Y')
                            ->placeholder('-'),
                        TextEntry::make('new_due_at')
                            ->label('Tenggat Baru')
                            ->date('d M Y')
                            ->placeholder('-'),
                        TextEntry::make('requester.name')
                            ->label('Diajukan oleh')
                            ->placeholder('-'),
                        TextEntry::make('approver.name')
                            ->label('Diproses oleh')
                            ->placeholder('-'),
                        TextEntry::make('reason')
                            ->label('Alasan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Diajukan pada')
                            ->dateTime('d M Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }
}
