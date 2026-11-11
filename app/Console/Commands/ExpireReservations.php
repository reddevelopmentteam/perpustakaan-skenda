<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * Kedaluwarsakan reservasi yang melewati batas ambil + oper antrean
 * ke pendaftar berikutnya (Tahap 9, §19 perpus.md).
 *
 * Idempotent penuh: aman dijalankan berulang (double-check status +
 * expires_at di dalam transaction per reservasi dengan lockForUpdate;
 * lihat `ReservationService::expireOverdue()`). Dijadwalkan `hourly()`
 * di routes/console.php.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Kedaluwarsakan reservasi yang melewati batas ambil dan oper antrean ke pendaftar berikutnya.';

    public function handle(): int
    {
        $expired = ReservationService::expireOverdue();

        $this->info("Berhasil memproses {$expired} reservasi kedaluwarsa.");

        return self::SUCCESS;
    }
}
