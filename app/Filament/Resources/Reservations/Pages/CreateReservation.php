<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Exceptions\LoanException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Models\Student;
use App\Services\ReservationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        $studentId = (int) ($data['student_id'] ?? 0);

        if ($user?->isStudent()) {
            $studentId = (int) $user->student?->id;
        }

        $student = Student::query()->findOrFail($studentId);

        try {
            /** @var Reservation $reservation */
            $reservation = ReservationService::reserve(
                $student,
                (int) ($data['book_id'] ?? 0),
                $user,
            );

            return $reservation;
        } catch (LoanException $e) {
            Notification::make()
                ->danger()
                ->title('Gagal membuat reservasi.')
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
        return 'Reservasi dibuat.';
    }
}
