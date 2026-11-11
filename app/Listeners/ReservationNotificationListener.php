<?php

namespace App\Listeners;

use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SATU listener notifikasi database untuk semua event string reservasi Tahap 9.
 *
 * Menulis langsung ke tabel `notifications` via DB::table insert (pola
 * Tahap 7/8 — tanpa notification class). Kolom: id uuid, type
 * 'reservation.{created,available,fulfilled,cancelled,expired}',
 * notifiable_type User::class, notifiable_id, data JSON
 * {reservation_number, message Bahasa Indonesia, url
 * '/admin/reservations/{id}'}, read_at null.
 *
 * Penerima: selalu siswa pemilik reservasi (Reservation->student->user).
 * Penerima null di-skip. Tanpa dedupe (lihat LoanAuditListener Tahap 7).
 *
 * CATATAN: 'reservation.available' yang lahir dari expire (oper antrean ke
 * reservasi berikutnya via ReservationService::expireOverdue) otomatis
 * ternotifikasi karena memakai event yang sama. Listener ini TIDAK memanggil
 * promoteNext — promosi sudah ditangani ReservationService::expireOverdue
 * (untuk kedaluwarsa) dan PromoteReservationOnReturn (untuk pengembalian).
 */
class ReservationNotificationListener
{
    public function handle(string $event, array $data): void
    {
        $payload = $data[0] ?? null;

        if (! is_string($event) || ! $payload instanceof Reservation) {
            return;
        }

        $this->record($event, $payload);
    }

    public function onCreated(Reservation $reservation): void
    {
        $this->record('reservation.created', $reservation);
    }

    public function onAvailable(Reservation $reservation): void
    {
        $this->record('reservation.available', $reservation);
    }

    public function onFulfilled(Reservation $reservation): void
    {
        $this->record('reservation.fulfilled', $reservation);
    }

    public function onCancelled(Reservation $reservation): void
    {
        $this->record('reservation.cancelled', $reservation);
    }

    public function onExpired(Reservation $reservation): void
    {
        $this->record('reservation.expired', $reservation);
    }

    private function record(string $event, Reservation $payload): void
    {
        $reservationNumber = $payload->reservation_number;
        $position = $payload->queue_position;
        $bookTitle = $payload->relationLoaded('book') && $payload->book !== null
            ? $payload->book->title
            : ($payload->book()->first()?->title ?? 'buku');
        $deadline = $payload->expires_at instanceof \DateTimeInterface
            ? $payload->expires_at->format('d M Y')
            : ($payload->expires_at !== null ? (string) $payload->expires_at : '-');

        $messages = [
            'reservation.created' => "Reservasi {$reservationNumber} diterima. Posisi antrean ke-{$position}.",
            'reservation.available' => "Buku {$bookTitle} sudah tersedia. Ambil sebelum {$deadline} sebelum reservasi kedaluwarsa.",
            'reservation.fulfilled' => "Reservasi {$reservationNumber} telah dipenuhi. Selamat membaca!",
            'reservation.cancelled' => "Reservasi {$reservationNumber} dibatalkan.",
            'reservation.expired' => "Reservasi {$reservationNumber} kedaluwarsa.",
        ];

        if (! isset($messages[$event])) {
            return;
        }

        $recipients = $this->ownerUsers($payload);

        if ($recipients === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($recipients as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => $event,
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'data' => json_encode([
                    'reservation_number' => $reservationNumber,
                    'message' => $messages[$event],
                    'url' => "/admin/reservations/{$payload->getKey()}",
                ], JSON_UNESCAPED_UNICODE),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($rows);
    }

    /** @return list<int> */
    private function ownerUsers(Reservation $reservation): array
    {
        $student = $reservation->relationLoaded('student')
            ? $reservation->student
            : $reservation->student()->first();

        if (! $student instanceof Student || $student->user_id === null) {
            return [];
        }

        return [$student->user_id];
    }
}
