<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detail Kategori')
                    ->components([
                        TextEntry::make('name')
                            ->label('Nama Kategori'),
                        TextEntry::make('slug')
                            ->label('Slug'),
                        TextEntry::make('books_count')
                            ->label('Jumlah Buku')
                            ->counts('books'),
                        TextEntry::make('description')
                            ->label('Deskripsi')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Daftar Buku')
                    ->components([
                        RepeatableEntry::make('books')
                            ->label('')
                            ->table([
                                TableColumn::make('Judul'),
                                TableColumn::make('Penulis'),
                                TableColumn::make('Stok'),
                            ])
                            ->schema([
                                TextEntry::make('title'),
                                TextEntry::make('author'),
                                TextEntry::make('stock'),
                            ])
                            ->placeholder('Belum ada buku dalam kategori ini.'),
                    ]),
            ]);
    }
}
