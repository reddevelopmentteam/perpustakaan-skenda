<?php

namespace App\Filament\Resources\Books\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Informasi Buku')
                    ->description('Detail utama koleksi buku.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('title')
                                ->label('Judul')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull()
                                ->validationMessages([
                                    'required' => 'Judul wajib diisi.',
                                    'max' => 'Judul maksimal 255 karakter.',
                                ]),
                            TextInput::make('author')
                                ->label('Penulis')
                                ->required()
                                ->maxLength(255)
                                ->validationMessages([
                                    'required' => 'Penulis wajib diisi.',
                                    'max' => 'Penulis maksimal 255 karakter.',
                                ]),
                            TextInput::make('isbn')
                                ->label('ISBN')
                                ->nullable()
                                ->unique(ignoreRecord: true)
                                ->regex('/^(\d{10}|\d{13})$/')
                                ->helperText('10 atau 13 digit, boleh kosong.')
                                ->validationMessages([
                                    'unique' => 'ISBN sudah digunakan.',
                                    'regex' => 'ISBN harus 10 atau 13 digit angka.',
                                ]),
                            TextInput::make('publisher')
                                ->label('Penerbit')
                                ->nullable()
                                ->maxLength(255),
                            TextInput::make('publication_year')
                                ->label('Tahun Terbit')
                                ->nullable()
                                ->numeric()
                                ->integer()
                                ->minValue(1000)
                                ->maxValue((int) date('Y'))
                                ->validationMessages([
                                    'integer' => 'Tahun terbit harus bilangan bulat.',
                                    'min' => 'Tahun terbit minimal 1000.',
                                    'max' => 'Tahun terbit maksimal tahun berjalan.',
                                ]),
                            Select::make('category_id')
                                ->label('Kategori')
                                ->relationship('category', 'name')
                                ->searchable()
                                ->preload()
                                ->nullable(),
                        ]),
                        Textarea::make('description')
                            ->label('Deskripsi')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Stok & Lokasi')
                    ->description('Pengelolaan ketersediaan dan penempatan fisik.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('stock')
                                ->label('Jumlah Stok')
                                ->required()
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Total eksemplar fisik.')
                                ->validationMessages([
                                    'required' => 'Stok wajib diisi.',
                                    'integer' => 'Stok harus bilangan bulat.',
                                    'min' => 'Stok minimal 0.',
                                ]),
                            TextInput::make('available_stock')
                                ->label('Stok Tersedia')
                                ->disabled()
                                ->dehydrated(false)
                                ->default(0)
                                ->helperText('Dikelola otomatis oleh sistem.'),
                            TextInput::make('shelf_location')
                                ->label('Lokasi Rak')
                                ->nullable()
                                ->maxLength(255)
                                ->placeholder('Rak-A-1'),
                        ]),
                    ]),
                Section::make('Cover')
                    ->schema([
                        FileUpload::make('cover')
                            ->label('Gambar Cover')
                            ->disk('public')
                            ->directory('covers')
                            ->image()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->helperText('Unggah cover untuk memudahkan identifikasi buku.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
