<?php

namespace App\Filament\Resources\Fines;

use App\Filament\Resources\Fines\Pages\ListFines;
use App\Filament\Resources\Fines\Pages\ViewFine;
use App\Filament\Resources\Fines\Schemas\FineInfolist;
use App\Filament\Resources\Fines\Tables\FinesTable;
use App\Models\Fine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FineResource extends Resource
{
    protected static ?string $model = Fine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Denda';

    protected static ?string $pluralModelLabel = 'Denda';

    public static function infolist(Schema $schema): Schema
    {
        return FineInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['loan', 'student', 'cashier']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFines::route('/'),
            'view' => ViewFine::route('/{record}'),
        ];
    }
}
