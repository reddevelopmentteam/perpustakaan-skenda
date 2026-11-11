<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * Kirim pengingat jatuh tempo (H-hari) + pengingat keterlambatan harian
 * (Tahap 11, §17 perpus.md).
 *
 * Idempotent per hari: aman dijalankan berulang (dedupe di
 * `ReminderService` berbasis baris notifications hari ini; lihat docblock
 * service). Dijadwalkan `dailyAt('07:00')` di routes/console.php.
 */
class SendLoanReminders extends Command
{
    protected $signature = 'loans:send-reminders';

    protected $description = 'Kirim pengingat jatuh tempo peminjaman (H-3/H-1/H0) dan pengingat keterlambatan harian.';

    public function handle(): int
    {
        $due = ReminderService::sendDueReminders();
        $overdue = ReminderService::sendOverdueReminders();

        $this->info("Berhasil mengirim {$due} pengingat jatuh tempo dan {$overdue} pengingat keterlambatan.");

        return self::SUCCESS;
    }
}
