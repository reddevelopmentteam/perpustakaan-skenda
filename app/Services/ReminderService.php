<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Pengingat jatuh tempo + keterlambatan peminjaman (Tahap 11, §17/§26/§35 perpus.md).
 *
 * KONTRAK EVENT (string-based, satu nama event — untuk slice Events/listener):
 * - Nama: 'loan.reminder' (konstanta self::EVENT).
 * - Payload: SATU array asosiatif (bukan multi-arg):
 *   `Event::dispatch('loan.reminder', [['loan' => $loan, 'kind' => $kind, 'days' => $days]])`
 *   - 'loan' => Loan yang bersangkutan.
 *   - 'kind' => 'due_soon' (H-hari sebelum jatuh tempo) | 'overdue' (sudah terlambat).
 *   - 'days' => int; untuk due_soon = sisa hari (H), untuk overdue = hari keterlambatan (>= 1).
 * - Listener menulis ke `notifications` dengan type 'loan.reminder' dan data JSON
 *   WAJIB memuat key: loan_number, kind, days (urutan bebas), plus message & url
 *   seperti listener loan.* existing (id uuid, notifiable = user siswa pemilik).
 *
 * DEDUPE / IDEMPOTENSI (scheduler boleh jalan ganda, §35/§52):
 * - Dedupe di SINI (service), sebelum dispatch: cek tabel `notifications`
 *   type='loan.reminder' + notifiable_id=user siswa + whereDate(created_at, hari ini)
 *   + data LIKE '%"loan_number":"<nomor>"%' (LIKE dipersempit), lalu bandingkan
 *   kind/days secara eksak via json_decode di PHP (menghindari false-positive
 *   substring seperti days=3 vs days=30 yang murni LIKE tidak bisa bedakan).
 * - Berbasis LIKE JSON: bukan ideal, tapi portable SQLite/MySQL. Tahap 13 boleh
 *   ganti kolom dedupe khusus.
 * - Konsekuensi: pengingat due_soon dikirim 1x per (loan, H) per hari;
 *   pengingat overdue 1x per (loan, lateDays) per hari — keesokan harinya
 *   lateDays berubah sehingga pengingat harian keterlambatan terkirim lagi.
 * - Tanpa lock (hanya baca + dispatch; listener yang menulis). Chunk 200.
 */
class ReminderService
{
    public const EVENT = 'loan.reminder';

    public const KIND_DUE_SOON = 'due_soon';

    public const KIND_OVERDUE = 'overdue';

    /**
     * Parse setting `reminder_days` (contoh '3,1,0') menjadi list int unik sort desc.
     *
     * Fallback [3, 1, 0] bila key absen/kosong/invalid (LibrarySeeder memang
     * belum membawa key ini — Tahap 11 tidak migrasi data, jadi fallback di sini).
     *
     * @return list<int>
     */
    public static function reminderDays(): array
    {
        $raw = Setting::get('reminder_days', '3,1,0');

        if (! is_string($raw) || trim($raw) === '') {
            return [3, 1, 0];
        }

        $days = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part === '' || ! ctype_digit($part)) {
                continue;
            }

            $days[] = (int) $part;
        }

        $days = array_values(array_unique($days));
        rsort($days);

        return $days === [] ? [3, 1, 0] : $days;
    }

    /**
     * Kirim pengingat H-hari sebelum jatuh tempo.
     *
     * Hanya loans BORROWED/PARTIALLY_RETURNED (BUKAN OVERDUE — itu ranah
     * mark-overdue) yang due_at-nya jatuh TEPAT H hari dari $today
     * (perbandingan TANGGAL via whereDate, bukan timestamp).
     *
     * @return int jumlah event yang di-dispatch (yang ter-dedupe tidak dihitung)
     */
    public static function sendDueReminders(?Carbon $today = null): int
    {
        $today ??= Carbon::now();
        $sent = 0;

        foreach (self::reminderDays() as $h) {
            $target = $today->copy()->addDays($h)->toDateString();

            Loan::query()
                ->with('student')
                ->whereIn('status', [LoanStatus::BORROWED->value, LoanStatus::PARTIALLY_RETURNED->value])
                ->whereDate('due_at', $target)
                ->orderBy('id')
                ->chunkById(200, function ($loans) use ($h, $today, &$sent): void {
                    foreach ($loans as $loan) {
                        $userId = $loan->student?->user_id;

                        if ($userId === null) {
                            continue;
                        }

                        if (self::alreadyRemindedToday($userId, $loan->loan_number, self::KIND_DUE_SOON, $h, $today)) {
                            continue;
                        }

                        Event::dispatch(self::EVENT, [[
                            'loan' => $loan,
                            'kind' => self::KIND_DUE_SOON,
                            'days' => $h,
                        ]]);

                        $sent++;
                    }
                });
        }

        return $sent;
    }

    /**
     * Kirim pengingat harian untuk loans berstatus OVERDUE.
     *
     * days = selisih hari now - due_at (min 1). Karena days ikut didedupe,
     * pengingat terkirim 1x per hari per jumlah-hari-terlambat.
     *
     * @return int jumlah event yang di-dispatch (yang ter-dedupe tidak dihitung)
     */
    public static function sendOverdueReminders(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $sent = 0;

        Loan::query()
            ->with('student')
            ->where('status', LoanStatus::OVERDUE->value)
            ->orderBy('id')
            ->chunkById(200, function ($loans) use ($now, &$sent): void {
                foreach ($loans as $loan) {
                    if ($loan->due_at === null) {
                        continue;
                    }

                    $userId = $loan->student?->user_id;

                    if ($userId === null) {
                        continue;
                    }

                    $lateDays = max(1, (int) $now->copy()->startOfDay()->diffInDays($loan->due_at->copy()->startOfDay(), true));

                    if (self::alreadyRemindedToday($userId, $loan->loan_number, self::KIND_OVERDUE, $lateDays, $now)) {
                        continue;
                    }

                    Event::dispatch(self::EVENT, [[
                        'loan' => $loan,
                        'kind' => self::KIND_OVERDUE,
                        'days' => $lateDays,
                    ]]);

                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Dedupe idempotensi: sudah ada notifikasi loan.reminder untuk
     * (user, loan_number, kind, days) pada tanggal $day?
     */
    private static function alreadyRemindedToday(int $userId, string $loanNumber, string $kind, int $days, Carbon $day): bool
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $loanNumber);

        $rows = DB::table('notifications')
            ->where('type', self::EVENT)
            ->where('notifiable_id', $userId)
            ->where('data', 'like', '%"loan_number":"'.$escaped.'"%')
            ->whereDate('created_at', $day->toDateString())
            ->pluck('data');

        foreach ($rows as $json) {
            $decoded = json_decode((string) $json, true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['kind'] ?? null) === $kind && (int) ($decoded['days'] ?? -1) === $days) {
                return true;
            }
        }

        return false;
    }
}
