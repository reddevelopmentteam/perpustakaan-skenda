<?php

namespace App\Listeners;

use App\Models\Fine;
use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SATU listener notifikasi database untuk event pengembalian & denda Tahap 8.
 *
 * Menulis langsung ke tabel `notifications` via DB::table insert (pola
 * Tahap 7 — tanpa notification class). Kolom: id uuid, type, notifiable_type
 * User::class, notifiable_id, data JSON {loan_number, amount?, message Bahasa
 * Indonesia, url}, read_at null.
 *
 * Penerima: selalu siswa pemilik peminjaman (Loan->student->user, atau
 * Fine->student->user / Fine->loan->student->user). Penerima null di-skip.
 * Tanpa dedupe (lihat LoanAuditListener Tahap 7).
 */
class ReturnNotificationListener
{
    public function handle(string $event, array $data): void
    {
        $payload = $data[0] ?? null;

        if (! is_string($event) || (! $payload instanceof Loan && ! $payload instanceof Fine)) {
            return;
        }

        $this->record($event, $payload);
    }

    public function onReturned(Loan $loan): void
    {
        $this->record('loan.returned', $loan);
    }

    public function onOverdue(Loan $loan): void
    {
        $this->record('loan.overdue', $loan);
    }

    public function onFineIssued(Fine $fine): void
    {
        $this->record('fine.issued', $fine);
    }

    public function onFinePaid(Fine $fine): void
    {
        $this->record('fine.paid', $fine);
    }

    public function onFineWaived(Fine $fine): void
    {
        $this->record('fine.waived', $fine);
    }

    private function record(string $event, Loan|Fine $payload): void
    {
        $loan = $payload instanceof Fine
            ? ($payload->relationLoaded('loan') ? $payload->loan : $payload->loan()->first())
            : $payload;

        if (! $loan instanceof Loan) {
            return;
        }

        $loanNumber = $loan->loan_number;
        $amountText = $payload instanceof Fine ? 'Rp'.number_format((int) $payload->amount, 0, ',', '.') : '';

        $messages = [
            'loan.returned' => "Buku {$loanNumber} telah dikembalikan. Terima kasih.",
            'loan.overdue' => "Peminjaman {$loanNumber} melewati jatuh tempo, segera kembalikan bukunya.",
            'fine.issued' => "Denda {$amountText} dikenakan untuk peminjaman {$loanNumber}.",
            'fine.paid' => "Denda {$amountText} untuk peminjaman {$loanNumber} telah dibayar.",
            'fine.waived' => "Denda {$amountText} untuk peminjaman {$loanNumber} telah dibebaskan.",
        ];

        if (! isset($messages[$event])) {
            return;
        }

        $recipients = $this->ownerUsers($loan, $payload instanceof Fine ? $payload : null);

        if ($recipients === []) {
            return;
        }

        $data = [
            'loan_number' => $loanNumber,
            'message' => $messages[$event],
            'url' => "/admin/loans/{$loan->getKey()}",
        ];

        if ($payload instanceof Fine) {
            $data['amount'] = (int) $payload->amount;
        }

        $now = now();
        $rows = [];

        foreach ($recipients as $userId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => $event,
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notifications')->insert($rows);
    }

    /** @return list<int> */
    private function ownerUsers(Loan $loan, ?Fine $fine = null): array
    {
        $student = null;

        if ($fine instanceof Fine) {
            $student = $fine->relationLoaded('student') ? $fine->student : $fine->student()->first();
        }

        if (! $student instanceof Student) {
            $student = $loan->relationLoaded('student') ? $loan->student : $loan->student()->first();
        }

        if (! $student instanceof Student || $student->user_id === null) {
            return [];
        }

        return [$student->user_id];
    }
}
