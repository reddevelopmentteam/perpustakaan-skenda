<?php

namespace App\Filament\Resources\BookCopies\Pages;

use App\Filament\Resources\BookCopies\BookCopyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBookCopy extends CreateRecord
{
    protected static string $resource = BookCopyResource::class;
}
