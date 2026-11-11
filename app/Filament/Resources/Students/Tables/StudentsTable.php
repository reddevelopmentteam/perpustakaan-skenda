<?php

namespace App\Filament\Resources\Students\Tables;

use App\Enums\StudentStatus;
use App\Filament\Resources\Students\Schemas\StudentForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_number')
                    ->label('NIS')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('class')
                    ->label('Kelas')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof StudentStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        StudentStatus::ACTIVE => 'success',
                        StudentStatus::BLOCKED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('user.name')
                    ->label('Akun')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('loans_count')
                    ->label('Pinjaman')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(StudentStatus::cases())->mapWithKeys(fn (StudentStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('class')
                    ->label('Kelas')
                    ->options(StudentForm::classOptions()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation(),
                RestoreAction::make(),
                ForceDeleteAction::make()->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->requiresConfirmation(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()->requiresConfirmation(),
                ]),
            ]);
    }
}
