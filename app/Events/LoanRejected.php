<?php

namespace App\Events;

use App\Models\Loan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoanRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Loan $loan;
    public string $reason;

    public function __construct(Loan $loan, string $reason)
    {
        $this->loan = $loan;
        $this->reason = $reason;
    }
}
