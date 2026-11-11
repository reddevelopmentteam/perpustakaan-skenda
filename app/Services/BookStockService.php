<?php

namespace App\Services;

use App\Enums\BookCopyStatus;
use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan stok terpusat (§20, §32, §33).
 *
 * Semua perubahan stok harus melalui service ini dalam DB transaction
 * dengan `lockForUpdate()` agar konsisten dan aman terhadap race condition.
 *
 * Stok keluar saat status eksemplar berjadi BORROWED (buku fisik diserahkan).
 * Stok kembali saat status eksemplar RETURNED.
 */
class BookStockService
{
    /**
     * Kurangi available_stock pada buku saat eksemplar dipinjam.
     *
     * Diperbolehkan hanya dalam transaksi yang sudah menahan lock pada baris buku.
     *
     * @throws QueryException
     */
    public static function decrement(Book $book): void
    {
        $book->decrement('available_stock');
    }

    /**
     * Tambah available_stock pada buku saat eksemplar dikembalikan.
     *
     * @throws QueryException
     */
    public static function increment(Book $book): void
    {
        $book->increment('available_stock');
    }

    /**
     * Hitung available_stock secara aktual dari status eksemplar.
     *
     * Sumber kebenaran = status book_copies, bukan cache available_stock.
     */
    public static function recount(Book $book): int
    {
        return (int) BookCopy::query()
            ->where('book_id', $book->id)
            ->where('status', BookCopyStatus::AVAILABLE->value)
            ->count();
    }

    /**
     * Sinkronkan available_stock dengan jumlah eksemplar tersedia.
     */
    public static function sync(Book $book): void
    {
        $book->update(['available_stock' => self::recount($book)]);
    }

    /**
     * Cek apakah masih ada stok tersedia.
     */
    public static function isAvailable(Book $book): bool
    {
        return BookCopy::query()
            ->where('book_id', $book->id)
            ->where('status', BookCopyStatus::AVAILABLE->value)
            ->exists();
    }

    /**
     * Ambil satu eksemplar tersedia dengan lock (untuk peminjaman).
     */
    public static function lockAvailableCopy(int $bookId): ?BookCopy
    {
        return BookCopy::query()
            ->where('book_id', $bookId)
            ->where('status', BookCopyStatus::AVAILABLE->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ubah status eksemplar dan sinkronkan stok buku dalam satu transaksi.
     *
     * @param  string  $newStatus  BookCopyStatus::* value
     * @throws \Exception
     */
    public static function changeCopyStatus(BookCopy $copy, string $newStatus, bool $syncStock = true): BookCopy
    {
        $oldStatus = $copy->status;
        $copy->update(['status' => $newStatus]);

        if ($syncStock && $oldStatus !== $newStatus) {
            $book = $copy->book;
            if ($book !== null) {
                DB::transaction(function () use ($book) {
                    self::sync($book);
                });
            }
        }

        return $copy->refresh();
    }
}
