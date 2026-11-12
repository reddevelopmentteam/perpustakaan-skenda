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
use App\Models\LoanItem;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Workflow peminjaman (Tahap 7). Semua method statis.
 *
 * Aturan mengikat:
 * - Setiap perubahan status `loans` WAJIB lewat `LoanStatus::canTransitionTo()`.
 * - Semua tulis kritis dalam `DB::transaction()`; baris buku dikunci
 *   `lockForUpdate()` sebelum stok dibaca/diubah (termasuk `decrement()`
 *   yang hanya dipanggil setelah lock dipegang).
 * - Stok (`books.available_stock`) hanya keluar di `markBorrowed()`.
 * - Nomor transaksi dari `LoanNumberService` dengan retry 3x untuk
 *   pelanggaran unique constraint (jaring pengaman anti-race).
 * - Event difire string-based (`Event::dispatch('loan.requested', [$loan])`)
 *   di DALAM transaksi setelah data tersimpan; slice Events/Listeners
 *   mendengarkan nama string ini:
 *   `loan.requested`, `loan.approved`, `loan.rejected`,
 *   `loan.borrowed`, `loan.cancelled` — payload `[Loan]`.
 */
class LoanService
{
    /**
     * Pengajuan peminjaman baru. Stok TIDAK berubah di sini.
     *
     * @param  array<int>  $bookIds
     */
    public static function request(Student $student, array $bookIds, ?User $by = null, ?string $notes = null): Loan
    {
        if (! $student->isBorrowable()) {
            throw new LoanException('Siswa tidak aktif atau diblokir sehingga tidak dapat mengajukan peminjaman.');
        }

        if ($bookIds === []) {
            throw new LoanException('Pilih minimal satu buku untuk dipinjam.');
        }

        if (count($bookIds) !== count(array_unique($bookIds))) {
            throw new LoanException('Terdapat buku yang sama lebih dari satu kali dalam pengajuan.');
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($student, $bookIds, $by, $notes) {
                    $books = Book::query()
                        ->whereIn('id', $bookIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($bookIds as $bookId) {
                        $book = $books->get($bookId);

                        if ($book === null) {
                            throw new LoanException('Buku tidak ditemukan.');
                        }

                        if ($book->available_stock <= 0) {
                            throw new LoanException("Stok buku \"{$book->title}\" tidak tersedia.");
                        }
                    }

                    $alreadyBorrowed = LoanItem::query()
                        ->whereIn('book_id', $bookIds)
                        ->whereNotIn('status', [LoanItemStatus::RETURNED->value, LoanItemStatus::CANCELLED->value])
                        ->whereHas('loan', fn ($query) => $query
                            ->where('student_id', $student->id)
                            ->active())
                        ->exists();

                    if ($alreadyBorrowed) {
                        throw new LoanException('Siswa sudah memiliki pinjaman aktif untuk salah satu buku tersebut.');
                    }

                    $maxLoans = (int) Setting::get('max_loans_per_student', '3');
                    $activeCount = $student->loans()->active()->count();

                    if ($activeCount + count($bookIds) > $maxLoans) {
                        throw new LoanException("Jumlah pinjaman siswa melebihi batas maksimal {$maxLoans} buku.");
                    }

                    $hasOverdue = $student->loans()
                        ->where('status', LoanStatus::OVERDUE->value)
                        ->exists();

                    if ($hasOverdue) {
                        throw new LoanException('Siswa mempunyai pinjaman terlambat. Selesaikan pengembalian terlebih dahulu.');
                    }

                    $loan = Loan::create([
                        'loan_number' => LoanNumberService::forLoan(),
                        'student_id' => $student->id,
                        'status' => LoanStatus::PENDING,
                        'requested_at' => now(),
                        'notes' => $notes,
                    ]);

                    foreach ($bookIds as $bookId) {
                        $loan->items()->create([
                            'book_id' => $bookId,
                            'book_copy_id' => null,
                            'quantity' => 1,
                            'status' => LoanItemStatus::PENDING,
                        ]);
                    }

                    AuditLog::create([
                        'user_id' => $by?->id,
                        'action' => 'loans.requested',
                        'auditable_type' => Loan::class,
                        'auditable_id' => $loan->id,
                        'new_values' => [
                            'loan_number' => $loan->loan_number,
                            'books' => array_values($bookIds),
                        ],
                    ]);

                    Event::dispatch('loan.requested', [$loan]);

                    return $loan->refresh();
                });
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! self::isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new LoanException('Gagal membuat nomor peminjaman. Silakan coba lagi.');
    }

    public static function approve(Loan $loan, User $staff, ?string $notes = null): Loan
    {
        self::assertTransition($loan, LoanStatus::APPROVED);

        return DB::transaction(function () use ($loan, $staff, $notes) {
            $loan->update([
                'status' => LoanStatus::APPROVED,
                'approved_at' => now(),
                'approved_by' => $staff->id,
                'notes' => $notes ?? $loan->notes,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loans.approved',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'old_values' => ['status' => LoanStatus::PENDING->value],
                'new_values' => ['status' => LoanStatus::APPROVED->value],
            ]);

            Event::dispatch('loan.approved', [$loan]);

            return $loan->refresh();
        });
    }

    public static function reject(Loan $loan, User $staff, string $reason): Loan
    {
        if (trim($reason) === '') {
            throw new LoanException('Alasan penolakan wajib diisi.');
        }

        self::assertTransition($loan, LoanStatus::REJECTED);

        return DB::transaction(function () use ($loan, $staff, $reason) {
            $loan->update([
                'status' => LoanStatus::REJECTED,
                'rejected_by' => $staff->id,
                'rejection_reason' => $reason,
            ]);

            $loan->items()
                ->where('status', LoanItemStatus::PENDING->value)
                ->update(['status' => LoanItemStatus::CANCELLED->value]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loans.rejected',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'old_values' => ['status' => LoanStatus::PENDING->value],
                'new_values' => ['status' => LoanStatus::REJECTED->value, 'reason' => $reason],
            ]);

            Event::dispatch('loan.rejected', [$loan]);

            return $loan->refresh();
        });
    }

    /**
     * Serah terima buku fisik. Stok keluar DI SINI.
     *
     * @param  array<int, int>  $copyIdsPerItem  [loan_item_id => book_copy_id]
     */
    public static function markBorrowed(Loan $loan, User $staff, array $copyIdsPerItem): Loan
    {
        self::assertTransition($loan, LoanStatus::BORROWED);

        return DB::transaction(function () use ($loan, $staff, $copyIdsPerItem) {
            $items = $loan->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty() || $items->count() !== count($copyIdsPerItem)) {
                throw new LoanException('Setiap item peminjaman wajib dipasangkan dengan eksemplar buku.');
            }

            $durationDays = (int) Setting::get('loan_duration_days', '7');
            $borrowedAt = now();
            $dueAt = (clone $borrowedAt)->addDays($durationDays);

            foreach ($items as $item) {
                if (! array_key_exists($item->id, $copyIdsPerItem)) {
                    throw new LoanException('Setiap item peminjaman wajib dipasangkan dengan eksemplar buku.');
                }

                $book = Book::query()->whereKey($item->book_id)->lockForUpdate()->first();

                if ($book === null) {
                    throw new LoanException('Buku tidak ditemukan.');
                }

                $copy = BookCopy::query()->whereKey($copyIdsPerItem[$item->id])->lockForUpdate()->first();

                if ($copy === null) {
                    throw new LoanException('Eksemplar buku tidak ditemukan.');
                }

                if ($copy->book_id !== $item->book_id) {
                    throw new LoanException("Eksemplar {$copy->inventory_code} bukan milik buku \"{$book->title}\".");
                }

                if (! $copy->status->isBorrowable()) {
                    throw new LoanException("Eksemplar {$copy->inventory_code} tidak tersedia.");
                }

                if ($book->available_stock <= 0) {
                    throw new LoanException("Stok buku \"{$book->title}\" tidak mencukupi.");
                }

                $copy->update(['status' => BookCopyStatus::BORROWED]);

                $item->update([
                    'book_copy_id' => $copy->id,
                    'status' => LoanItemStatus::BORROWED,
                    'due_at' => $dueAt,
                ]);

                // Aman: baris buku sudah dikunci lockForUpdate() di atas dalam transaksi ini.
                $book->decrement('available_stock');
            }

            $loan->update([
                'status' => LoanStatus::BORROWED,
                'borrowed_at' => $borrowedAt,
                'due_at' => $dueAt,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loans.borrowed',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'old_values' => ['status' => LoanStatus::APPROVED->value],
                'new_values' => [
                    'status' => LoanStatus::BORROWED->value,
                    'due_at' => $dueAt->toDateTimeString(),
                    'copies' => array_values($copyIdsPerItem),
                ],
            ]);

            Event::dispatch('loan.borrowed', [$loan]);

            return $loan->refresh();
        });
    }

    public static function cancel(Loan $loan, User $actor): Loan
    {
        self::assertTransition($loan, LoanStatus::CANCELLED);

        return DB::transaction(function () use ($loan, $actor) {
            $from = $loan->status;

            $loan->update(['status' => LoanStatus::CANCELLED]);

            $loan->items()
                ->where('status', LoanItemStatus::PENDING->value)
                ->update(['status' => LoanItemStatus::CANCELLED->value]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'loans.cancelled',
                'auditable_type' => Loan::class,
                'auditable_id' => $loan->id,
                'old_values' => ['status' => $from->value],
                'new_values' => ['status' => LoanStatus::CANCELLED->value],
            ]);

            Event::dispatch('loan.cancelled', [$loan]);

            return $loan->refresh();
        });
    }

    /**
     * Cari buku lewat nomor induk eksemplar (alur scan §11 langkah 3-5).
     * Mengembalikan Book beserta eksemplar yang masih tersedia.
     */
    public static function findByInventoryCode(string $inventoryCode): ?Book
    {
        $copy = BookCopy::query()->where('inventory_code', $inventoryCode)->first();

        if ($copy === null) {
            return null;
        }

        return Book::query()
            ->with(['copies' => fn ($query) => $query->where('status', BookCopyStatus::AVAILABLE->value)])
            ->find($copy->book_id);
    }

    private static function assertTransition(Loan $loan, LoanStatus $to): void
    {
        if (! $loan->status->canTransitionTo($to)) {
            throw new LoanException(
                "Status {$loan->status->label()} tidak dapat diubah ke {$to->label()}."
            );
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
