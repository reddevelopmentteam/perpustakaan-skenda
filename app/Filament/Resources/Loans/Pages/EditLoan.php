<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use Filament\Schemas\Schema;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(\App\Filament\Resources\Loans\Schemas\LoanForm::editSchema());
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Hanya catatan yang dapat diubah lewat halaman edit; field lain
        // tidak ditampilkan sama sekali.
        return ['notes' => $data['notes'] ?? null];
    }
}
