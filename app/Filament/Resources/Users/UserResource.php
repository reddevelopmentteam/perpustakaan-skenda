<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Paksa peran STUDENT dan status aktif default saat staf membuat akun,
     * serta cegah eskalasi peran / penonaktifan diri sendiri saat menyimpan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function guardRoleEscalation(array $data, ?User $target = null): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            $data['role'] ??= UserRole::STUDENT->value;

            return $data;
        }

        if ($actor->isSuperAdmin()) {
            if ($target instanceof User && $target->id === $actor->id) {
                unset($data['role'], $data['is_active']);
            }

            return $data;
        }

        // Aktor staf: hanya boleh mengelola akun siswa.
        $data['role'] = UserRole::STUDENT->value;

        if ($target instanceof User && $target->id === $actor->id) {
            unset($data['role'], $data['is_active']);
        }

        return $data;
    }
}
