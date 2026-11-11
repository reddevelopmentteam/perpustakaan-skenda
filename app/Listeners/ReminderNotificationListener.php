<?php

namespace App\Listeners;

use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listener notifikasi database untuk event string `loan.reminder`.
 *
 * Kontrak dengan slice Core (`ReminderService::EVENT`): service mem-fire
 * `Event::dispatch('loan.reminder', [['loan' => Loan, 'kind' => string, 'days' => int]])`
 * dengan kind 'due_soon' (days = sisa hari H dari reminderDays) atau
 * 'overdue' (days = hari keterlambatan, min 1).
 *
 * Toleransi payload (didokumentasikan): handler `handle($payload, $extra = null)`
 * menormalisasi dua bentuk —
 *  1. array asosiatif dengan key 'loan' (bentuk kontrak; 'kind'/'days' opsional),
 *  2. Loan langsung sebagai argumen pertama + array meta ['kind' => ..., 'days' => ...]
 *     sebagai argumen kedua (gaya `Event::dispatch('loan.reminder', [$loan, $meta])`).
 *
 * Menulis langsung ke tabel `notifications` via DB::table insert (pola existing
 * Tahap 7/8): id uuid, type 'loan.reminder', notifiable_type User::class,
 * notifiable_id = user siswa pemilik, data JSON
 * {loan_number, kind, days, message Bahasa Indonesia, url '/admin/loans/{id}'},
 * read_at null.
 *
 * Guard anti-ganda lapis kedua (service sudah dedupe per user+loan+kind+days
 * hari ini): sebelum insert, cek baris `loan.reminder` untuk user yang sama
 * yang datanya memuat loan_number + kind + days yang sama (LIKE pada kolom
 * JSON — aman di SQLite, pola sama seperti dedupe service); skip bila sudah
 * ada. Membuat listener aman walau event difire 2x (scheduler ganda / retry).
 */
class ReminderNotificationListener
{
    /**
     * @param  array{loan?: mixed, kind?: mixed, days?: mixed}|Loan|mixed  $payload
     */
    public function handle(mixed $payload, mixed $extra = null): void
    {
        $normalized = $this->normalize($payload, $extra);

        if ($normalized === null) {
            return;
        }

        $this->record($normalized['loan'], $normalized['kind'], $normalized['days']);
    }

    /**
     * @return array{loan: Loan, kind: string, days: int}|null
     */
    private function normalize(mixed $payload, mixed $extra = null): ?array
    {
        if (is_array($payload) && ($payload['loan'] ?? null) instanceof Loan) {
            return [
                'loan' => $payload['loan'],
                'kind' => (string) ($payload['kind'] ?? ''),
                'days' => (int) ($payload['days'] ?? 0),
            ];
        }

        if ($payload instanceof Loan) {
            $meta = is_array($extra) ? $extra : [];

            return [
                'loan' => $payload,
                'kind' => (string) ($meta['kind'] ?? ''),
                'days' => (int) ($meta['days'] ?? 0),
            ];
        }

        return null;
    }

    private function record(Loan $loan, string $kind, int $days): void
    {
        $student = $loan->relationLoaded('student') ? $loan->student : $loan->student()->first();

        if (! $student instanceof Student || $student->user_id === null) {
            return;
        }

        $userId = $student->user_id;
        $dueText = $loan->due_at
            ? \Illuminate\Support\Carbon::parse($loan->due_at)->format('d/m/Y')
            : '-';

        if ($kind === 'overdue') {
            $message = "Peminjaman {$loan->loan_number} sudah terlambat {$days} hari. Segera kembalikan bukunya.";
        } elseif ($days <= 0) {
            $message = "Pengingat: buku peminjaman {$loan->loan_number} jatuh tempo hari ini ({$dueText}).";
        } else {
            $message = "Pengingat: buku peminjaman {$loan->loan_number} jatuh tempo dalam {$days} hari ({$dueText}).";
        }

        // Guard lapis kedua: skip bila notifikasi reminder yang sama sudah ada.
        $like = fn (string $fragment): string => '%'.addcslashes($fragment, '\\%_').'%';

        $exists = DB::table('notifications')
            ->where('type', 'loan.reminder')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('data', 'like', $like('"loan_number":"'.$loan->loan_number.'"'))
            ->where('data', 'like', $like('"kind":"'.$kind.'"'))
            ->where('data', 'like', $like('"days":'.$days))
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'loan.reminder',
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode([
                'loan_number' => $loan->loan_number,
                'kind' => $kind,
                'days' => $days,
                'message' => $message,
                'url' => "/admin/loans/{$loan->getKey()}",
            ], JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
