<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Models\Book;
use App\Models\Student;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class LoanForm
{
    public static function editSchema(): array
    {
        return [
            Textarea::make('notes')
                ->label('Catatan')
                ->nullable()
                ->columnSpanFull()
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Peminjaman')
                    ->icon('heroicon-m-clipboard-document-list')
                    ->compact()
                    ->schema([
                        Select::make('student_id')
                            ->label('Siswa')
                            ->relationship('student', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Student $record): string => "{$record->name} ({$record->student_number} · {$record->class})")
                            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'active')->orderBy('name'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->validationMessages([
                                'required' => 'Siswa wajib dipilih.',
                            ])
                            ->default(function (): ?int {
                                $user = auth()->user();

                                return $user?->student?->id;
                            })
                            ->disabled(fn (): bool => (bool) auth()->user()?->isStudent()),

                        Repeater::make('books')
                            ->label('Buku yang Dipinjam')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah buku')
                            ->columnSpanFull()
                            ->columns(1)
                            ->schema([
                                Select::make('book_id')
                                    ->label('Buku')
                                    ->options(fn (): array => Book::query()
                                        ->where('available_stock', '>', 0)
                                        ->orderBy('title')
                                        ->limit(200)
                                        ->get()
                                        ->mapWithKeys(fn (Book $book): array => [
                                            $book->id => "{$book->title} — {$book->author} (tersedia: {$book->available_stock})",
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Buku wajib dipilih.',
                                    ]),
                            ]),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->nullable()
                            ->columnSpanFull()
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->columns(1),
            ]);
    }
}
