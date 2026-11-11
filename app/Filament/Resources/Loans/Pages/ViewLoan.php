<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Tables\LoansTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LoansTable::approveAction(),
            LoansTable::rejectAction(),
            LoansTable::markBorrowedAction(),
            LoansTable::cancelAction(),
            EditAction::make(),
        ];
    }
}
