<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('role')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof UserRole ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        UserRole::SUPER_ADMIN => 'violet',
                        UserRole::STAFF => 'warning',
                        default => 'info',
                    }),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Login Terakhir')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('-'),
                TextColumn::make('student.student_number')
                    ->label('NIS')
                    ->toggleable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Peran')
                    ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])->all()),
                TernaryFilter::make('is_active')
                    ->label('Aktif')
                    ->trueLabel('Aktif saja')
                    ->falseLabel('Nonaktif saja'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
