<?php

namespace App\Notifications;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Loan $loan;
    public int $daysLate;
    public string $message;

    public function __construct(Loan $loan, int $daysLate, string $message)
    {
        $this->loan = $loan;
        $this->daysLate = $daysLate;
        $this->message = $message;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'loan_id' => $this->loan->id,
            'loan_number' => $this->loan->loan_number,
            'days_late' => $this->daysLate,
            'message' => $this->message,
            'type' => 'reminder',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'loan_id' => $this->loan->id,
            'loan_number' => $this->loan->loan_number,
            'days_late' => $this->daysLate,
            'message' => $this->message,
            'type' => 'reminder',
            'read' => false,
        ];
    }
}
