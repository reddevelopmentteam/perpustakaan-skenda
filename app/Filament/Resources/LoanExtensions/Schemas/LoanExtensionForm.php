<?php

namespace App\Filament\Resources\LoanExtensions\Schemas;

use App\Models\Loan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class LoanExtensionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pengajuan Perpanjangan')
                    ->icon('heroicon-m-arrow-path')
                    ->compact()
                    ->schema([
                        Select::make('loan_id')
                            ->label('Peminjaman')
                            ->relationship('loan', 'loan_number', function (Builder $query): Builder {
                                $query->active()->with('student')->orderByDesc('due_at');

                                $user = auth()->user();

                                if ($user?->isStudent() && $user->student) {
                                    $query->where('student_id', $user->student->id);
                                }

                                return $query;
                            })
                            ->getOptionLabelFromRecordUsing(fn (Loan $record): string => $record->loan_number
                                .' — '.($record->student?->name ?? 'Tanpa siswa')
                                .' (jatuh tempo: '.($record->due_at?->format('d M Y') ?? '-').')')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->validationMessages([
                                'required' => 'Peminjaman wajib dipilih.',
                            ]),

                        Textarea::make('reason')
                            ->label('Alasan Perpanjangan')
                            ->nullable()
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull()
                            ->placeholder('Contoh: buku masih dibutuhkan untuk tugas akhir bab 3.'),
                    ])
                    ->columns(1),
            ]);
    }
}
