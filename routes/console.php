<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tahap 8: tandai pinjaman terlambat + terbitkan denda berjalan.
// Ubah ke ->everyFifteenMinutes() bila operasional butuh penandaan lebih cepat.
Schedule::command('loans:mark-overdue')->hourly();
// Tahap 9: kedaluwarsakan reservasi + oper antrean (idempotent).
Schedule::command('reservations:expire')->hourly();
// Tahap 11: pengingat jatuh tempo (H-hari) + keterlambatan harian (idempotent per hari).
Schedule::command('loans:send-reminders')->dailyAt('07:00');
