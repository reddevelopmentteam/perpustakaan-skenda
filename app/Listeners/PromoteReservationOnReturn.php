<?php

namespace App\Listeners;

use App\Enums\LoanStatus;
use App\Models\Book;
use App\Models\Loan;
use App\Services\ReservationService;

/**
 * Listener TAMBAHAN untuk event string 'loan.returned' Tahap 9.
 *
 * File LoanAuditListener/LoanNotificationListener milik Tahap 7 DILARANG
 * diubah, tetapi Event::listen membolehkan banyak listener untuk satu event
 * yang sama — listener ini didaftarkan berdampingan dengan
 * ReturnAuditListener::onReturned dan ReturnNotificationListener::onReturned.
 *
 * Tugas: setiap kali peminjaman dikembalikan, promosikan antrean reservasi
 * berikutnya untuk tiap buku unik dalam loan tersebut via
 * ReservationService::promoteNext(). Guard: hanya jalan bila loan berstatus
 * RETURNED/PARTIALLY_RETURNED (pengembalian penuh maupun sebagian dapat
 * membebaskan eksemplar). Kegagalan promosi satu buku di-catch per buku agar
 * tidak menggagalkan buku lain. Listener ini TIDAK dispatch event sendiri —
 * promoteNext() sudah dispatch 'reservation.available'.
 */
class PromoteReservationOnReturn
{
    public function handle(Loan $loan): void
    {
        if (! $loan instanceof Loan) {
            return;
        }

        if (! in_array($loan->status, [LoanStatus::RETURNED, LoanStatus::PARTIALLY_RETURNED], true)) {
            return;
        }

        // Tanpa N+1: satu query pluck, tanpa memuat model item.
        $bookIds = $loan->items()->pluck('book_id')->unique()->values();

        foreach ($bookIds as $bookId) {
            try {
                $book = Book::query()->whereKey($bookId)->first();

                if ($book === null) {
                    continue;
                }

                ReservationService::promoteNext($book);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
