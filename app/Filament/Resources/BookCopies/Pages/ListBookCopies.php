<?php

namespace App\Filament\Resources\BookCopies\Pages;

use App\Filament\Resources\BookCopies\BookCopyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBookCopies extends ListRecords
{
    protected static string $resource = BookCopyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah Eksemplar'),
            Action::make('cetakBarcode')
                ->label('Cetak Barcode')
                ->icon('heroicon-o-printer')
                ->action(function (): void {
                    Notification::make()
                        ->title('Fitur cetak nomor induk menyusul di Tahap 6 lanjutan')
                        ->info()
                        ->send();
                }),
        ];
    }
}
