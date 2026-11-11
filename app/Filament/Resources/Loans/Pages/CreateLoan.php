<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Exceptions\LoanException;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Models\Student;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        $studentId = (int) ($data['student_id'] ?? 0);

        if ($user?->isStudent()) {
            $studentId = (int) $user->student?->id;
        }

        $student = Student::query()->findOrFail($studentId);

        $bookIds = collect($data['books'] ?? [])
            ->pluck('book_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        try {
            /** @var Loan $loan */
            $loan = app(\App\Services\LoanService::class)->request(
                $student,
                $bookIds,
                $user,
                $data['notes'] ?? null,
            );

            return $loan;
        } catch (LoanException $e) {
            Notification::make()
                ->danger()
                ->title('Gagal membuat peminjaman.')
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
        return 'Permintaan peminjaman dibuat.';
    }
}
