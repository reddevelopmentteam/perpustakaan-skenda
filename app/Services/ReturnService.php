<?php

namespace App\Services;

use App\Enums\BookCopyStatus;
use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Exceptions\LoanException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Pengembalian buku (Tahap 8, §14 perpus.md). Semua method statis.
 *
 * Aturan mengikat:
 * - Setiap perubahan status `loans` WAJIB lewat `LoanStatus::canTransitionTo()`.
 * - Semua tulis kritis dalam `DB::transaction()` dengan `lockForUpdate()`
 *   (loan -> items -> copy/book) sebelum stok dibaca/diubah.
 * - `books.available_stock` hanya di-increment di sini (tidak pernah
 *   decrement); increment dilakukan SETELAH lock baris book dipegang.
 * - Idempotent: item yang sudah RETURNED di-skip diam-diam sehingga
 *   double-click/browser-refresh tidak menggandakan stok.
 * - Denda dihitung via `FineService::issueForLoan()` (tepat waktu -> null,
 *   terlambat -> buat/update satu baris UNPAID).
 * - Event string-based `loan.returned` (payload `[Loan]`) difire di DALAM
 *   transaksi setelah data tersimpan; audit ditulis langsung di service
 *   (konsisten dengan LoanService Tahap 7).
 *
 * Batasan: kondisi fisik eksemplar (hilang/rusak) di luar scope — copy
 * selalu dikembalikan ke AVAILABLE. Status LOST/DAMAGED ditangani Tahap 9.
 */
class ReturnService
{
    /**
     * Proses pengembalian (penuh atau sebagian).
     *
     * @param  array<int>  $copyIds  list book_copy_id yang dikembalikan;
     *                              kosong = kembalikan SEMUA item aktif.
     */
    public static function process(Loan $loan, User $staff, array $copyIds = [], ?string $notes = null): Loan
    {
        // Idempotensi penuh (spek §52 item #2): loan yang sudah RETURNED
        // adalah no-op — return apa adanya, tanpa exception, tanpa ubah stok.
        if ($loan->status === LoanStatus::RETURNED) {
            return $loan;
        }

        if (! in_array($loan->status, [
            LoanStatus::BORROWED,
            LoanStatus::PARTIALLY_RETURNED,
            LoanStatus::OVERDUE,
        ], true)) {
            throw new LoanException('Peminjaman tidak dalam status dipinjam.');
        }

        return DB::transaction(function () use ($loan, $staff, $copyIds, $notes) {
            $from = $loan->status;

            // Kunci baris loan agar dua pengembalian bersamaan tidak balapan.
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            // Re-check di dalam lock: balapan dua request bersamaan — yang
            // kedua menjadi no-op idempotent (tanpa exception, tanpa ubah stok).
            if ($locked->status === LoanStatus::RETURNED) {
                return $locked;
            }

            $active = $items->whereIn('status', [LoanItemStatus::BORROWED, LoanItemStatus::OVERDUE]);

            if ($active->isEmpty()) {
                return $locked;
            }

            // [] = kembalikan semua item aktif; subset = pengembalian sebagian.
            $wanted = $copyIds === [] ? $active->pluck('book_copy_id')->all() : array_values(array_unique($copyIds));

            $returnedCopies = [];

            foreach ($wanted as $copyId) {
                $item = $items->firstWhere('book_copy_id', $copyId);

                if ($item === null) {
                    throw new LoanException('Eksemplar tidak tercatat dipinjam pada transaksi ini.');
                }

                // Idempotensi: sudah kembali -> skip diam-diam.
                if ($item->status === LoanItemStatus::RETURNED) {
                    continue;
                }

                if (! in_array($item->status, [LoanItemStatus::BORROWED, LoanItemStatus::OVERDUE], true)) {
                    throw new LoanException('Eksemplar tidak tercatat dipinjam pada transaksi ini.');
                }

                $book = Book::query()->whereKey($item->book_id)->lockForUpdate()->first();

                if ($book === null) {
                    throw new LoanException('Buku tidak ditemukan.');
                }

                $copy = BookCopy::query()->whereKey($copyId)->lockForUpdate()->first();

                if ($copy === null) {
                    throw new LoanException('Eksemplar buku tidak ditemukan.');
                }

                $item->update([
                    'status' => LoanItemStatus::RETURNED,
                    'returned_at' => now(),
                    'returned_quantity' => $item->quantity,
                ]);

                $copy->update(['status' => BookCopyStatus::AVAILABLE]);

                // Aman: baris buku sudah dikunci lockForUpdate() di atas dalam transaksi ini.
                $book->increment('available_stock');

                $returnedCopies[] = $copyId;
            }

            // Semua yang diminta sudah RETURNED -> no-op idempotent:
            // tanpa tulis audit/stok/event.
            if ($returnedCopies === []) {
                return $locked->refresh();
            }

            $remaining = $locked->items()
                ->whereIn('status', [LoanItemStatus::BORROWED->value, LoanItemStatus::OVERDUE->value])
                ->count();

            $to = $remaining === 0 ? LoanStatus::RETURNED : LoanStatus::PARTIALLY_RETURNED;

            if ($locked->status !== $to) {
                self::assertTransition($locked, $to);

                $locked->update(array_merge(
                    ['status' => $to],
                    $to === LoanStatus::RETURNED ? ['returned_at' => now()] : []
                ));
            }

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loans.returned',
                'auditable_type' => Loan::class,
                'auditable_id' => $locked->id,
                'old_values' => ['status' => $from->value],
                'new_values' => [
                    'status' => $locked->refresh()->status->value,
                    'copies' => $returnedCopies,
                    'notes' => $notes,
                ],
            ]);

            if ($notes !== null && $notes !== '') {
                $locked->update(['notes' => $notes]);
            }

            FineService::issueForLoan($locked->refresh());

            Event::dispatch('loan.returned', [$locked]);

            return $locked->refresh();
        });
    }

    private static function assertTransition(Loan $loan, LoanStatus $to): void
    {
        if (! $loan->status->canTransitionTo($to)) {
            throw new LoanException(
                "Status {$loan->status->label()} tidak dapat diubah ke {$to->label()}."
            );
        }
    }
}
