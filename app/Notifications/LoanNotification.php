<?php

namespace App\Notifications;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class LoanNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Loan $loan;
    public string $event;
    public string $message;
    public string $url;

    public function __construct(Loan $loan, string $event, string $message, string $url)
    {
        $this->loan = $loan;
        $this->event = $event;
        $this->message = $message;
        $this->url = $url;
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
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'loan_id' => $this->loan->id,
            'loan_number' => $this->loan->loan_number,
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
            'read' => false,
        ];
    }
}
