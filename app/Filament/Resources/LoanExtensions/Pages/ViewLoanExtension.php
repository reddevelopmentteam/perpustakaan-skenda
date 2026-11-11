<?php

namespace App\Filament\Resources\LoanExtensions\Pages;

use App\Filament\Resources\LoanExtensions\LoanExtensionResource;
use App\Filament\Resources\LoanExtensions\Tables\LoanExtensionsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewLoanExtension extends ViewRecord
{
    protected static string $resource = LoanExtensionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LoanExtensionsTable::approveAction(),
            LoanExtensionsTable::rejectAction(),
        ];
    }
}
