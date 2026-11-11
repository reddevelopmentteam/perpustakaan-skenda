<?php

namespace App\Filament\Resources\BookCopies\Pages;

use App\Filament\Resources\BookCopies\BookCopyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBookCopy extends ViewRecord
{
    protected static string $resource = BookCopyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
