<?php

namespace App\Events;

use App\Models\Fine;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinePaid
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Fine $fine;

    public function __construct(Fine $fine)
    {
        $this->fine = $fine;
    }
}
