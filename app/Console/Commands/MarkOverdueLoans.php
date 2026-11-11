<?php

namespace App\Console\Commands;

use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\FineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Tandai pinjaman yang melewati jatuh tempo sebagai OVERDUE + terbitkan
 * denda berjalan (Tahap 8, §15 perpus.md).
 *
 * Idempotent penuh: aman dijalankan berulang (double-check status + due
 * di dalam transaction per loan dengan lockForUpdate; denda di-update,
 * bukan dibuat ganda). Dijadwalkan `hourly()` di routes/console.php —
 * ubah ke `everyFifteenMinutes()` bila operasional membutuhkan
 * penandaan lebih cepat (cukup ganti pemanggilan Schedule di sana).
 */
class MarkOverdueLoans extends Command
{
    protected $signature = 'loans:mark-overdue';

    protected $description = 'Tandai pinjaman yang melewati jatuh tempo sebagai terlambat (overdue) dan terbitkan dendanya.';

    public function handle(): int
    {
        $marked = 0;

        Loan::query()
            ->whereIn('status', [LoanStatus::BORROWED->value, LoanStatus::PARTIALLY_RETURNED->value])
            ->where('due_at', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($loans) use (&$marked): void {
                foreach ($loans as $loan) {
                    DB::transaction(function () use ($loan, &$marked): void {
                        $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->first();

                        if ($locked === null) {
                            return;
                        }

                        // Double-check: masih aktif menunggak dan due benar lewat.
                        if (! in_array($locked->status, [LoanStatus::BORROWED, LoanStatus::PARTIALLY_RETURNED], true)) {
                            return;
                        }

                        if ($locked->due_at === null || ! $locked->due_at->isPast()) {
                            return;
                        }

                        if ($locked->status->canTransitionTo(LoanStatus::OVERDUE)) {
                            $locked->update(['status' => LoanStatus::OVERDUE]);
                        }

                        $locked->items()
                            ->where('status', LoanItemStatus::BORROWED->value)
                            ->where('due_at', '<', now())
                            ->update(['status' => LoanItemStatus::OVERDUE->value]);

                        FineService::issueForLoan($locked->refresh());

                        Event::dispatch('loan.overdue', [$locked]);

                        $marked++;
                    });
                }
            });

        $this->info("Berhasil menandai {$marked} pinjaman sebagai terlambat.");

        return self::SUCCESS;
    }
}
