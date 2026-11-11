<?php

namespace App\Events;

use App\Models\LoanExtension;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExtensionApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public LoanExtension $extension;

    public function __construct(LoanExtension $extension)
    {
        $this->extension = $extension;
    }
}
