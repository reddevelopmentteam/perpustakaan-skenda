<?php

namespace App\Filament\Resources\LoanExtensions;

use App\Filament\Resources\LoanExtensions\Pages\CreateLoanExtension;
use App\Filament\Resources\LoanExtensions\Pages\ListLoanExtensions;
use App\Filament\Resources\LoanExtensions\Pages\ViewLoanExtension;
use App\Filament\Resources\LoanExtensions\Schemas\LoanExtensionForm;
use App\Filament\Resources\LoanExtensions\Schemas\LoanExtensionInfolist;
use App\Filament\Resources\LoanExtensions\Tables\LoanExtensionsTable;
use App\Models\LoanExtension;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoanExtensionResource extends Resource
{
    protected static ?string $model = LoanExtension::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Perpanjangan';

    protected static ?string $pluralModelLabel = 'Perpanjangan';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return LoanExtensionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LoanExtensionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanExtensionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['loan.student', 'requester', 'approver']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanExtensions::route('/'),
            'create' => CreateLoanExtension::route('/create'),
            'view' => ViewLoanExtension::route('/{record}'),
        ];
    }
}
