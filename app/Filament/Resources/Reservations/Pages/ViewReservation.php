<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReservationsTable::cancelAction(),
            ReservationsTable::fulfillAction(),
        ];
    }
}
