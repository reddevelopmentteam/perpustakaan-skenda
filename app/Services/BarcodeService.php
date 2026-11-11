<?php

namespace App\Services;

use App\Models\BookCopy;
use Illuminate\Support\Str;

/**
 * Generate barcode unik per eksemplar fisik (§6).
 *
 * Format: BK-000001 (prefix + 6 digit angka berurutan).
 * Nomor digenerate dengan retry untuk menghindari duplikasi race condition.
 */
class BarcodeService
{
    public const PREFIX = 'BK';

    /**
     * Generate barcode unik untuk eksemplar baru.
     *
     * @param  int  $digits  Jumlah digit angka (default 6 -> BK-000001)
     * @param  int  $maxAttempts  Maksimal percobaan jika bentrok
     * @return string
     */
    public static function generate(int $digits = 6, int $maxAttempts = 10): string
    {
        $pad = str_repeat('0', $digits);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $number = mt_rand(1, (int) str_repeat('9', $digits));
            $barcode = self::PREFIX . '-' . str_pad((string) $number, $digits, '0', STR_PAD_LEFT);

            if (! BookCopy::query()->where('barcode', $barcode)->exists()) {
                return $barcode;
            }
        }

        // Fallback: cari angka terbesar yang ada lalu +1
        $last = BookCopy::query()
            ->where('barcode', 'like', self::PREFIX . '-%')
            ->orderByDesc('barcode')
            ->value('barcode');

        if ($last !== null) {
            $num = (int) Str::after($last, self::PREFIX . '-') + 1;
        } else {
            $num = 1;
        }

        return self::PREFIX . '-' . str_pad((string) $num, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Validasi format barcode.
     */
    public static function isValid(string $barcode): bool
    {
        return (bool) preg_match('/^' . self::PREFIX . '-\d{6}$/', $barcode);
    }
}
