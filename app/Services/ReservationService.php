<?php

namespace App\Services;

use App\Enums\LoanItemStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\LoanException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Antrean reservasi buku (Tahap 9, perpus.md §19, §27, §30, §32, §33, §52).
 * Semua method statis; error bisnis memakai LoanException (pesan Indonesia).
 *
 * Peta transisi status (ReservationStatus TIDAK punya canTransitionTo,
 * jadi validasi manual via assertTransition()):
 * - waiting   -> [available, cancelled, expired]
 * - available -> [fulfilled, cancelled, expired]
 * - fulfilled / expired / cancelled -> [] (terminal)
 *
 * Kontrak untuk slice Events (hook pengembalian):
 * - ReturnService milik Tahap 8 dilarang diubah, jadi slice ini TIDAK membuat
 *   listener. Slice Events yang mendengarkan 'loan.returned' WAJIB memanggil
 *   `ReservationService::promoteNext($book)` dari listener tersebut.
 *   `promoteNext()` idempotent: bila sudah ada reservasi AVAILABLE untuk buku
 *   itu, ia dikembalikan apa adanya tanpa membuat duplikat, sehingga pemanggilan
 *   ganda (double-fire event, retry) aman.
 *
 * Keputusan stok (didokumentasikan eksplisit):
 * - `promoteNext()` TIDAK mengubah `book_copies`/`available_stock`. Eksemplar
 *   sudah AVAILABLE oleh ReturnService; reservasi menandai PRIORITAS pengambilan
 *   (pendaftar pertama dilayani dulu), bukan hard-hold fisik. Bila operasional
 *   butuh hard-hold (eksemplar dikunci untuk pendaftar), itu scope Tahap 11+.
 * - Setelah `cancel()`, queue_position TIDAK dirapatkan (posisi historis).
 *   `promoteNext()` selalu mengambil WAITING dengan queue_position terkecil,
 *   jadi lubang nomor antrean tidak memengaruhi urutan.
 * - `fulfill()` TIDAK membuat Loan; petugas membuat peminjaman lewat alur
 *   pinjam biasa (LoanService), lalu menandai antrean selesai via fulfill().
 */
class ReservationService
{
    /**
     * Buat reservasi baru (status WAITING) di ujung antrean buku.
     *
     * @throws LoanException
     */
    public static function reserve(Student $student, int $bookId, ?User $by = null): Reservation
    {
        if (! $student->isBorrowable()) {
            throw new LoanException('Siswa tidak aktif atau diblokir sehingga tidak dapat membuat reservasi.');
        }

        if (Reservation::query()
            ->where('student_id', $student->id)
            ->where('book_id', $bookId)
            ->active()
            ->exists()) {
            throw new LoanException('Siswa sudah mempunyai reservasi aktif untuk buku ini.');
        }

        $borrowing = LoanItem::query()
            ->where('book_id', $bookId)
            ->whereNotIn('status', [LoanItemStatus::RETURNED->value, LoanItemStatus::CANCELLED->value])
            ->whereHas('loan', fn ($query) => $query
                ->where('student_id', $student->id)
                ->active())
            ->exists();

        if ($borrowing) {
            throw new LoanException('Siswa sedang meminjam buku ini sehingga tidak perlu reservasi.');
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($student, $bookId, $by) {
                    $book = Book::query()->whereKey($bookId)->lockForUpdate()->first();

                    if ($book === null) {
                        throw new LoanException('Buku tidak ditemukan.');
                    }

                    $maxQueue = Reservation::query()
                        ->where('book_id', $book->id)
                        ->whereIn('status', [
                            ReservationStatus::WAITING->value,
                            ReservationStatus::AVAILABLE->value,
                        ])
                        ->lockForUpdate()
                        ->max('queue_position');

                    $reservation = Reservation::create([
                        'reservation_number' => LoanNumberService::forReservation(),
                        'student_id' => $student->id,
                        'book_id' => $book->id,
                        'status' => ReservationStatus::WAITING,
                        'reserved_at' => now(),
                        'queue_position' => ((int) $maxQueue) + 1,
                    ]);

                    AuditLog::create([
                        'user_id' => $by?->id,
                        'action' => 'reservations.created',
                        'auditable_type' => Reservation::class,
                        'auditable_id' => $reservation->id,
                        'new_values' => [
                            'reservation_number' => $reservation->reservation_number,
                            'book_id' => $book->id,
                            'queue_position' => $reservation->queue_position,
                        ],
                    ]);

                    Event::dispatch('reservation.created', [$reservation]);

                    return $reservation->refresh();
                });
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! self::isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new LoanException('Gagal membuat nomor reservasi. Silakan coba lagi.');
    }

    /**
     * Batalkan reservasi aktif. Siswa hanya boleh membatalkan miliknya;
     * petugas/super admin boleh membatalkan siapa saja.
     *
     * @throws LoanException
     */
    public static function cancel(Reservation $res, User $actor): Reservation
    {
        if (! $res->isActive()) {
            throw new LoanException('Reservasi sudah tidak aktif sehingga tidak dapat dibatalkan.');
        }

        if ($actor->isStudent() && $actor->student?->id !== $res->student_id) {
            throw new LoanException('Reservasi ini bukan milik Anda sehingga tidak dapat dibatalkan.');
        }

        $from = $res->status;
        self::assertTransition($from, ReservationStatus::CANCELLED);

        return DB::transaction(function () use ($res, $actor, $from) {
            $res->update([
                'status' => ReservationStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'reservations.cancelled',
                'auditable_type' => Reservation::class,
                'auditable_id' => $res->id,
                'old_values' => ['status' => $from->value],
                'new_values' => ['status' => ReservationStatus::CANCELLED->value],
            ]);

            Event::dispatch('reservation.cancelled', [$res]);

            return $res->refresh();
        });
    }

    /**
     * Oper antrean: tandai WAITING terdepan sebagai AVAILABLE.
     *
     * Idempotent — bila sudah ada reservasi AVAILABLE untuk buku ini, ia
     * dikembalikan apa adanya (tidak dibuat ganda). Dipanggil slice Events
     * dari listener 'loan.returned' dan dari `expireOverdue()`.
     */
    public static function promoteNext(Book $book, ?User $staff = null): ?Reservation
    {
        return DB::transaction(function () use ($book, $staff) {
            $existing = Reservation::query()
                ->where('book_id', $book->id)
                ->where('status', ReservationStatus::AVAILABLE->value)
                ->lockForUpdate()
                ->orderBy('queue_position')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $next = Reservation::query()
                ->where('book_id', $book->id)
                ->where('status', ReservationStatus::WAITING->value)
                ->lockForUpdate()
                ->orderBy('queue_position')
                ->first();

            if ($next === null) {
                return null;
            }

            $holdDays = max(1, (int) Setting::get('reservation_hold_days', '3'));

            // Validasi transisi agar konsisten dengan peta (waiting -> available).
            self::assertTransition($next->status, ReservationStatus::AVAILABLE);

            $next->update([
                'status' => ReservationStatus::AVAILABLE,
                'expires_at' => now()->addDays($holdDays),
            ]);

            AuditLog::create([
                'user_id' => $staff?->id,
                'action' => 'reservations.available',
                'auditable_type' => Reservation::class,
                'auditable_id' => $next->id,
                'old_values' => ['status' => ReservationStatus::WAITING->value],
                'new_values' => [
                    'status' => ReservationStatus::AVAILABLE->value,
                    'expires_at' => $next->expires_at?->toDateTimeString(),
                ],
            ]);

            Event::dispatch('reservation.available', [$next]);

            return $next->refresh();
        });
    }

    /**
     * Tandai reservasi AVAILABLE selesai diambil (buku sudah dipinjam lewat
     * alur pinjam biasa oleh petugas — Loan TIDAK dibuat di sini).
     *
     * @throws LoanException
     */
    public static function fulfill(Reservation $res, User $staff): Reservation
    {
        if ($res->status !== ReservationStatus::AVAILABLE) {
            throw new LoanException('Hanya reservasi yang siap diambil yang dapat diselesaikan.');
        }

        return DB::transaction(function () use ($res, $staff) {
            $res->update([
                'status' => ReservationStatus::FULFILLED,
                'fulfilled_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'reservations.fulfilled',
                'auditable_type' => Reservation::class,
                'auditable_id' => $res->id,
                'old_values' => ['status' => ReservationStatus::AVAILABLE->value],
                'new_values' => ['status' => ReservationStatus::FULFILLED->value],
            ]);

            Event::dispatch('reservation.fulfilled', [$res]);

            return $res->refresh();
        });
    }

    /**
     * Kedaluwarsakan reservasi AVAILABLE yang lewat batas ambil, lalu oper
     * antrean ke WAITING berikutnya. Idempotent (2x run aman via double-check
     * status di dalam transaksi + lock per baris).
     */
    public static function expireOverdue(?Carbon $now = null): int
    {
        $now ??= now();
        $expired = 0;

        Reservation::query()
            ->where('status', ReservationStatus::AVAILABLE->value)
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->chunkById(200, function ($reservations) use ($now, &$expired): void {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, $now, &$expired): void {
                        $locked = Reservation::query()
                            ->whereKey($reservation->id)
                            ->lockForUpdate()
                            ->first();

                        if ($locked === null) {
                            return;
                        }

                        // Double-check: masih AVAILABLE dan benar lewat batas.
                        if ($locked->status !== ReservationStatus::AVAILABLE) {
                            return;
                        }

                        if ($locked->expires_at === null || ! $locked->expires_at->lt($now)) {
                            return;
                        }

                        $locked->update(['status' => ReservationStatus::EXPIRED]);

                        AuditLog::create([
                            'user_id' => null,
                            'action' => 'reservations.expired',
                            'auditable_type' => Reservation::class,
                            'auditable_id' => $locked->id,
                            'old_values' => ['status' => ReservationStatus::AVAILABLE->value],
                            'new_values' => ['status' => ReservationStatus::EXPIRED->value],
                        ]);

                        Event::dispatch('reservation.expired', [$locked]);

                        $expired++;
                    });

                    // Oper ke antrean berikutnya di luar transaksi expire agar
                    // lock tidak ditahan lebih lama dari perlu.
                    $book = $reservation->book;
                    if ($book !== null) {
                        self::promoteNext($book);
                    }
                }
            });

        return $expired;
    }

    /**
     * Validasi manual peta transisi (enum tidak punya canTransitionTo):
     * waiting->[available,cancelled,expired], available->[fulfilled,cancelled,expired].
     *
     * @throws LoanException
     */
    private static function assertTransition(ReservationStatus $from, ReservationStatus $to): void
    {
        $allowed = match ($from) {
            ReservationStatus::WAITING => [
                ReservationStatus::AVAILABLE,
                ReservationStatus::CANCELLED,
                ReservationStatus::EXPIRED,
            ],
            ReservationStatus::AVAILABLE => [
                ReservationStatus::FULFILLED,
                ReservationStatus::CANCELLED,
                ReservationStatus::EXPIRED,
            ],
            ReservationStatus::FULFILLED,
            ReservationStatus::EXPIRED,
            ReservationStatus::CANCELLED => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new LoanException(
                "Status reservasi \"{$from->label()}\" tidak dapat diubah menjadi \"{$to->label()}\"."
            );
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        return (string) $sqlState === '23000';
    }
}
