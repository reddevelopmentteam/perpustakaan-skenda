<?php

namespace App\Filament\Resources\BookCopies\Pages;

use App\Enums\BookCopyStatus;
use App\Filament\Resources\BookCopies\BookCopyResource;
use App\Models\BookCopy;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBookCopy extends EditRecord
{
    protected static string $resource = BookCopyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (BookCopy $record): bool => $record->status !== BookCopyStatus::BORROWED),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
