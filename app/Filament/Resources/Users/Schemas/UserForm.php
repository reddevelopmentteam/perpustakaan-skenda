<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Nama wajib diisi.',
                    ]),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Email wajib diisi.',
                        'email' => 'Format email tidak valid.',
                        'unique' => 'Email sudah terdaftar.',
                    ]),
                TextInput::make('username')
                    ->label('Username')
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->validationMessages([
                        'unique' => 'Username sudah terdaftar.',
                    ]),
                TextInput::make('password')
                    ->label('Kata Sandi')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(8)
                    ->confirmed()
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Kosongkan bila tidak ingin mengubah kata sandi.' : null)
                    ->validationMessages([
                        'required' => 'Kata sandi wajib diisi.',
                        'min' => 'Kata sandi minimal :min karakter.',
                        'confirmed' => 'Konfirmasi kata sandi tidak cocok.',
                    ]),
                TextInput::make('password_confirmation')
                    ->label('Konfirmasi Kata Sandi')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(false),
                Select::make('role')
                    ->label('Peran')
                    ->options(collect(UserRole::cases())->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])->all())
                    ->default(UserRole::STUDENT->value)
                    ->required()
                    ->disabled(fn (?User $record): bool => $record?->id === auth()->id() || (! auth()->user()?->isSuperAdmin() ?? true))
                    ->dehydrated(fn (?User $record): bool => $record?->id !== auth()->id() && (bool) auth()->user()?->isSuperAdmin())
                    ->validationMessages([
                        'required' => 'Peran wajib dipilih.',
                    ]),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                    ->dehydrated(fn (?User $record): bool => $record?->id !== auth()->id()),
            ])
            ->columns(2);
    }
}
