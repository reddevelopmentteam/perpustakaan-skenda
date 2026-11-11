<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor transaksi human-readable yang aman dari race condition.
 *
 * Format: `PJ-YYYYMMDD-0001` (peminjaman) dan `RSV-YYYYMMDD-0001` (reservasi),
 * 4 digit urutan naik per hari kalender. Semua method statis.
 *
 * Strategi anti-race:
 * 1. Setiap pemanggilan berjalan dalam `DB::transaction()` dan mengunci baris
 *    nomor terakhir hari ini via `lockForUpdate()` (`SELECT ... FOR UPDATE`),
 *    sehingga dua proses bersamaan diserialisasi: proses kedua menunggu hingga
 *    transaksi pertama commit sebelum membaca nomor terakhir.
 * 2. Celah yang tersisa: bila belum ada nomor sama sekali hari ini, tidak ada
 *    baris yang bisa dikunci sehingga dua transaksi bisa menghitung `-0001`
 *    yang sama. Celah ini ditutup oleh unique constraint kolom nomor sebagai
 *    jaring pengaman + retry (maks. 3x dengan generate ulang) di sisi pemanggil
 *    (lihat `LoanService::request()`).
 * 3. Cache `lastIssued` per proses: dua pemanggilan berurutan dalam proses yang
 *    sama (mis. generate nomor tanpa simpan di antaranya) tetap menghasilkan
 *    nomor berbeda dan naik.
 * 4. Catatan MySQL produksi: untuk menutup celah butir 2 sepenuhnya, gunakan
 *    baris sentinel/sequence harian (satu baris per tanggal yang selalu bisa
 *    di-lock). Di SQLite dev `lockForUpdate()` adalah no-op sehingga pola
 *    unique-constraint + retry adalah perlindungan utamanya — pola ini portable
 *    untuk kedua driver.
 *
 * Dilarang memakai `count()+1` tanpa lock (AGENTS.md §3.9).
 */
class LoanNumberService
{
    /** @var array<string, int> nomor urut terakhir per prefix+tanggal dalam proses ini */
    private static array $lastIssued = [];

    public static function forLoan(): string
    {
        return self::nextNumber(Loan::class, 'loan_number', 'PJ-');
    }

    public static function forReservation(): string
    {
        return self::nextNumber(Reservation::class, 'reservation_number', 'RSV-');
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private static function nextNumber(string $model, string $column, string $prefix): string
    {
        $date = now()->format('Ymd');

        return DB::transaction(function () use ($model, $column, $prefix, $date) {
            $last = $model::query()
                ->where($column, 'like', $prefix.$date.'-%')
                ->orderBy($column, 'desc')
                ->lockForUpdate()
                ->value($column);

            $sequence = 0;
            if (is_string($last) && preg_match('/(\d{4})$/', $last, $matches) === 1) {
                $sequence = (int) $matches[1];
            }

            $key = $prefix.$date;
            $sequence = max($sequence, self::$lastIssued[$key] ?? 0) + 1;
            self::$lastIssued[$key] = $sequence;

            return sprintf('%s%s-%04d', $prefix, $date, $sequence);
        });
    }
}
