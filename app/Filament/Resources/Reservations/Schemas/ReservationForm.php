<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Models\Book;
use App\Models\Student;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data Reservasi')
                    ->icon('heroicon-m-bookmark')
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

                        Select::make('book_id')
                            ->label('Buku')
                            ->options(fn (): array => Book::query()
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
                    ])
                    ->columns(1),
            ]);
    }
}
