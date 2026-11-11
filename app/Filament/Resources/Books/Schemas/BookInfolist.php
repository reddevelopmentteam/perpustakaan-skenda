<?php

namespace App\Filament\Resources\Books\Schemas;

use App\Enums\BookCopyStatus;
use App\Enums\LoanItemStatus;
use App\Models\Book;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detail Buku')
                    ->components([
                        ImageEntry::make('cover')
                            ->label('Cover')
                            ->disk('public')
                            ->columnSpanFull(),
                        TextEntry::make('title')
                            ->label('Judul'),
                        TextEntry::make('author')
                            ->label('Penulis'),
                        TextEntry::make('publisher')
                            ->label('Penerbit')
                            ->placeholder('-'),
                        TextEntry::make('publication_year')
                            ->label('Tahun Terbit')
                            ->placeholder('-'),
                        TextEntry::make('category.name')
                            ->label('Kategori')
                            ->badge()
                            ->placeholder('Tanpa kategori'),
                        TextEntry::make('shelf_location')
                            ->label('Lokasi Rak')
                            ->placeholder('-'),
                        TextEntry::make('isbn')
                            ->label('ISBN')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('stock')
                            ->label('Stok')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('available_stock')
                            ->label('Stok Tersedia')
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : 'Habis'),
                        TextEntry::make('description')
                            ->label('Deskripsi')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Statistik Eksemplar')
                    ->components([
                        TextEntry::make('copies_total')
                            ->label('Total Eksemplar')
                            ->state(fn (Book $record): int => $record->copies()->count()),
                        TextEntry::make('copies_available')
                            ->label('Tersedia')
                            ->badge()
                            ->color('success')
                            ->state(fn (Book $record): int => $record->copies()->where('status', BookCopyStatus::AVAILABLE)->count()),
                        TextEntry::make('copies_borrowed')
                            ->label('Dipinjam')
                            ->badge()
                            ->color('info')
                            ->state(fn (Book $record): int => $record->copies()->where('status', BookCopyStatus::BORROWED)->count()),
                        TextEntry::make('copies_damaged_lost')
                            ->label('Rusak / Hilang')
                            ->badge()
                            ->color('danger')
                            ->state(fn (Book $record): int => $record->copies()
                                ->whereIn('status', [BookCopyStatus::DAMAGED, BookCopyStatus::LOST])
                                ->count()),
                    ])
                    ->columns(4),
                Section::make('Riwayat Peminjaman Terakhir')
                    ->components([
                        RepeatableEntry::make('loanItems')
                            ->label('')
                            ->state(fn (Book $record): iterable => $record->loanItems()->with(['loan.student'])->latest()->limit(10)->get())
                            ->table([
                                TableColumn::make('Nomor'),
                                TableColumn::make('Siswa'),
                                TableColumn::make('Status'),
                                TableColumn::make('Jatuh Tempo'),
                            ])
                            ->schema([
                                TextEntry::make('loan.loan_number')
                                    ->placeholder('-'),
                                TextEntry::make('loan.student.name')
                                    ->placeholder('-'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (LoanItemStatus|string|null $state): string => match ($state) {
                                        LoanItemStatus::PENDING => 'warning',
                                        LoanItemStatus::BORROWED => 'info',
                                        LoanItemStatus::RETURNED => 'success',
                                        LoanItemStatus::OVERDUE, LoanItemStatus::LOST, LoanItemStatus::DAMAGED => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (LoanItemStatus|string|null $state): string => $state instanceof LoanItemStatus ? $state->label() : (string) ($state ?? '-')),
                                TextEntry::make('due_at')
                                    ->date('d M Y')
                                    ->placeholder('-'),
                            ])
                            ->placeholder('Belum ada riwayat peminjaman.'),
                    ]),
            ]);
    }
}
