<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loans';

    protected static ?string $title = 'Peminjaman';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('loan_number')
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor Pinjaman')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        LoanStatus::BORROWED => 'info',
                        LoanStatus::OVERDUE => 'danger',
                        LoanStatus::RETURNED => 'success',
                        LoanStatus::PENDING, LoanStatus::APPROVED, LoanStatus::PARTIALLY_RETURNED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('borrowed_at')
                    ->label('Dipinjam')
                    ->date()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('due_at')
                    ->label('Jatuh Tempo')
                    ->date()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    protected function canCreate(): bool
    {
        return false;
    }
}
