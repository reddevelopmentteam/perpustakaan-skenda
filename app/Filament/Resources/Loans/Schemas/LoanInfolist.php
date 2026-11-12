<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Models\Loan;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Peminjaman')
                    ->icon('heroicon-m-clipboard-document-list')
                    ->compact()
                    ->schema([
                        TextEntry::make('loan_number')
                            ->label('Nomor Pinjaman')
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
                            ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => \App\Filament\Resources\Loans\Tables\LoansTable::statusColor($state)),
                    ])
                    ->columns(3),

                Section::make('Linimasa')
                    ->icon('heroicon-m-clock')
                    ->compact()
                    ->schema([
                        TextEntry::make('requested_at')
                            ->label('Diminta')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('approved_at')
                            ->label('Disetujui')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('approver.name')
                            ->label('Disetujui oleh')
                            ->placeholder('-'),
                        TextEntry::make('borrowed_at')
                            ->label('Dipinjam')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('due_at')
                            ->label('Jatuh Tempo')
                            ->dateTime('d M Y')
                            ->placeholder('-')
                            ->color(fn (Loan $record): ?string => $record->due_at !== null
                                && $record->due_at->isPast()
                                && $record->isActive() ? 'danger' : null),
                        TextEntry::make('returned_at')
                            ->label('Dikembalikan')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->placeholder('-')
                            ->visible(fn (Loan $record): bool => $record->rejection_reason !== null)
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Buku Dipinjam')
                    ->icon('heroicon-m-book-open')
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->table([
                                TableColumn::make('Buku'),
                                TableColumn::make('Barcode'),
                                TableColumn::make('Status'),
                                TableColumn::make('Jatuh Tempo'),
                            ])
                            ->schema([
                                TextEntry::make('book.title')
                                    ->label('Buku')
                                    ->placeholder('-'),
                                TextEntry::make('copy.inventory_code')
                                    ->label('Nomor Induk')
                                    ->placeholder('-')
                                    ->copyable(),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\LoanItemStatus ? $state->label() : (string) $state),
                                TextEntry::make('due_at')
                                    ->label('Jatuh Tempo')
                                    ->date('d M Y')
                                    ->placeholder('-'),
                            ])
                            ->placeholder('Belum ada buku.'),
                    ]),

                Section::make('Denda Terkait')
                    ->icon('heroicon-m-banknotes')
                    ->compact()
                    ->visible(fn (Loan $record): bool => $record->fines()->exists())
                    ->schema([
                        RepeatableEntry::make('fines')
                            ->label('')
                            ->table([
                                TableColumn::make('Jumlah'),
                                TableColumn::make('Status'),
                                TableColumn::make('Dibayar'),
                            ])
                            ->schema([
                                TextEntry::make('amount')
                                    ->label('Jumlah')
                                    ->money('IDR'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof FineStatus ? $state->label() : (string) $state),
                                TextEntry::make('paid_at')
                                    ->label('Dibayar')
                                    ->dateTime('d M Y H:i')
                                    ->placeholder('-'),
                            ]),
                    ]),

                Section::make('Perpanjangan')
                    ->icon('heroicon-m-arrow-path')
                    ->compact()
                    ->visible(fn (Loan $record): bool => $record->extensions()->exists())
                    ->schema([
                        RepeatableEntry::make('extensions')
                            ->label('')
                            ->table([
                                TableColumn::make('Tenggat Lama'),
                                TableColumn::make('Tenggat Baru'),
                                TableColumn::make('Status'),
                                TableColumn::make('Alasan'),
                            ])
                            ->schema([
                                TextEntry::make('old_due_at')
                                    ->label('Tenggat Lama')
                                    ->date('d M Y')
                                    ->placeholder('-'),
                                TextEntry::make('new_due_at')
                                    ->label('Tenggat Baru')
                                    ->date('d M Y')
                                    ->placeholder('-'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\ExtensionStatus ? $state->label() : (string) $state),
                                TextEntry::make('reason')
                                    ->label('Alasan')
                                    ->placeholder('-'),
                            ]),
                    ]),
            ]);
    }
}
