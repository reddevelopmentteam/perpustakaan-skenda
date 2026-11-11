<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Models\Loan;
use App\Models\LoanExtension;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SATU listener notifikasi database untuk semua event string loan.
 *
 * Menulis langsung ke tabel `notifications` via DB::table insert (paling
 * stabil — tanpa notification class). Kolom: id uuid, type 'loan.{event}',
 * notifiable_type User::class, notifiable_id, data JSON
 * {loan_number, message Bahasa Indonesia, url '/admin/loans/{id}'},
 * read_at null.
 *
 * Penerima:
 * - loan.requested -> semua STAFF + SUPER_ADMIN aktif.
 * - loan.approved/rejected/borrowed -> user siswa pemilik (student->user).
 * - loan.cancelled -> staff-like (STAFF + SUPER_ADMIN aktif).
 * - loan.extension_requested -> staff-like.
 * - loan.extension_approved/rejected -> siswa pemilik.
 *
 * Penerima null di-skip. Tanpa dedupe (lihat docblock LoanAuditListener —
 * batasan untuk Tahap 11, query JSON SQLite rapuh).
 */
class LoanNotificationListener
{
    public function handle(string $event, array $data): void
    {
        $payload = $data[0] ?? null;

        if (! is_string($event) || (! $payload instanceof Loan && ! $payload instanceof LoanExtension)) {
            return;
        }

        $this->record($event, $payload);
    }

    public function onRequested(Loan $loan): void
    {
        $this->record('loan.requested', $loan);
    }

    public function onApproved(Loan $loan): void
    {
        $this->record('loan.approved', $loan);
    }

    public function onRejected(Loan $loan): void
    {
        $this->record('loan.rejected', $loan);
    }

    public function onBorrowed(Loan $loan): void
    {
        $this->record('loan.borrowed', $loan);
    }

    public function onCancelled(Loan $loan): void
    {
        $this->record('loan.cancelled', $loan);
    }

    public function onExtensionRequested(LoanExtension $extension): void
    {
        $this->record('loan.extension_requested', $extension);
    }

    public function onExtensionApproved(LoanExtension $extension): void
    {
        $this->record('loan.extension_approved', $extension);
    }

    public function onExtensionRejected(LoanExtension $extension): void
    {
        $this->record('loan.extension_rejected', $extension);
    }

    private function record(string $event, Loan|LoanExtension $payload): void
    {
        $loan = $payload instanceof LoanExtension
            ? ($payload->relationLoaded('loan') ? $payload->loan : $payload->loan()->first())
            : $payload;

        if (! $loan instanceof Loan) {
            return;
        }

        $messages = [
            'loan.requested' => "Pengajuan peminjaman {$loan->loan_number} menunggu persetujuan.",
            'loan.approved' => "Peminjaman {$loan->loan_number} telah disetujui.",
            'loan.rejected' => "Peminjaman {$loan->loan_number} ditolak.",
            'loan.borrowed' => "Peminjaman {$loan->loan_number} sudah dibukukan keluar, silakan ambil bukunya.",
            'loan.cancelled' => "Peminjaman {$loan->loan_number} dibatalkan.",
            'loan.extension_requested' => "Permintaan perpanjangan {$loan->loan_number} menunggu persetujuan.",
            'loan.extension_approved' => "Perpanjangan {$loan->loan_number} telah disetujui.",
            'loan.extension_rejected' => "Perpanjangan {$loan->loan_number} ditolak.",
        ];

        if (! isset($messages[$event])) {
            return;
        }

        $recipients = match ($event) {
            'loan.approved', 'loan.rejected', 'loan.borrowed',
            'loan.extension_approved', 'loan.extension_rejected' => $this->ownerUsers($loan),
            default => $this->staffUsers(),
        };

        if ($recipients === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($recipients as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => 'loan.'.substr($event, strlen('loan.')),
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'data' => json_encode([
                    'loan_number' => $loan->loan_number,
                    'message' => $messages[$event],
                    'url' => "/admin/loans/{$loan->getKey()}",
                ], JSON_UNESCAPED_UNICODE),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($rows);
    }

    /** @return list<int> */
    private function staffUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::STAFF, UserRole::SUPER_ADMIN])
            ->pluck('id')
            ->all();
    }

    /** @return list<int> */
    private function ownerUsers(Loan $loan): array
    {
        $student = $loan->relationLoaded('student') ? $loan->student : $loan->student()->first();

        if (! $student instanceof Student || $student->user_id === null) {
            return [];
        }

        return [$student->user_id];
    }
}
