<?php

namespace App\Filament\Resources\LoanExtensions\Pages;

use App\Exceptions\LoanException;
use App\Filament\Resources\LoanExtensions\LoanExtensionResource;
use App\Models\Loan;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLoanExtension extends CreateRecord
{
    protected static string $resource = LoanExtensionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        $loan = Loan::query()->findOrFail($data['loan_id'] ?? 0);

        try {
            /** @var \App\Models\LoanExtension $extension */
            $extension = app(\App\Services\LoanExtensionService::class)->request(
                $loan,
                $user,
                $data['reason'] ?? null,
            );

            return $extension;
        } catch (LoanException $e) {
            Notification::make()
                ->danger()
                ->title('Gagal mengajukan perpanjangan.')
                ->body($e->getMessage())
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Perpanjangan diajukan.';
    }
}
