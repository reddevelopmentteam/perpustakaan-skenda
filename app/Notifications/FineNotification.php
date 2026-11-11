<?php

namespace App\Notifications;

use App\Models\Fine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class FineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Fine $fine;
    public string $event;
    public string $message;
    public string $url;

    public function __construct(Fine $fine, string $event, string $message, string $url)
    {
        $this->fine = $fine;
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
            'fine_id' => $this->fine->id,
            'amount' => $this->fine->amount,
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'fine_id' => $this->fine->id,
            'amount' => $this->fine->amount,
            'event' => $this->event,
            'message' => $this->message,
            'url' => $this->url,
            'read' => false,
        ];
    }
}
