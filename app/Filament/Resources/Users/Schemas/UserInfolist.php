<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Pengguna')
                    ->description('Informasi akun dan status pengguna')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nama'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(),
                        TextEntry::make('username')
                            ->label('Username')
                            ->placeholder('-'),
                        TextEntry::make('role')
                            ->label('Peran')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof UserRole ? $state->label() : (string) $state)
                            ->color(fn ($state): string => match ($state) {
                                UserRole::SUPER_ADMIN => 'violet',
                                UserRole::STAFF => 'warning',
                                default => 'info',
                            }),
                        IconEntry::make('is_active')
                            ->label('Aktif')
                            ->boolean(),
                        TextEntry::make('last_login_at')
                            ->label('Login Terakhir')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('student.student_number')
                            ->label('NIS')
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}