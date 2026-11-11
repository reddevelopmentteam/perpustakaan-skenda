<?php

namespace App\Events;

use App\Models\Loan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoanOverdue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Loan $loan;

    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }
}
