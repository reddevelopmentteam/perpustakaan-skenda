<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReservationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Reservation $reservation;
    public string $event;
    public string $message;
    public string $url;

    public function __construct(Reservation $reservation, string $event, string $message, string $url)
    {
        $this->reservation = $reservation;
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
            'reservation_id' => $this->reservation->id,
            'reservation_number' => $this->reservation->reservation_number,
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'reservation_number' => $this->reservation->reservation_number,
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
            'read' => false,
        ];
    }
}
