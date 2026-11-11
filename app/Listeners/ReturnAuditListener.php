<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\Loan;

/**
 * SATU listener audit untuk event string pengembalian & denda Tahap 8.
 *
 * Kontrak dengan slice ReturnFine: event memakai string-based dengan payload
 * tunggal model: 'loan.returned'/'loan.overdue' [Loan], 'fine.issued'/
 * 'fine.paid'/'fine.waived' [Fine]. Service mem-fire event 1x di dalam transaksi.
 *
 * ANTI-DUPLIKAT (kontrak ReturnFine, diverifikasi ke kode service):
 * ReturnService menulis AuditLog 'loans.returned' (user_id = staff pemroses),
 * FineService menulis 'fines.paid' (user_id = kasir paid_by) dan
 * 'fines.waived' (user_id = staff) LANGSUNG di dalam transaksi. Listener ini
 * SENGAJA tidak menulis audit untuk ketiga event itu — onReturned/onFinePaid/
 * onFineWaived adalah no-op agar tidak terjadi baris audit ganda. Listener
 * hanya mencatat 'loans.overdue' (scheduler MarkOverdueLoans, tanpa audit di
 * service) dan 'fines.issued' (FineService::issueForLoan tanpa audit).
 * user_id keduanya null (sistem), new_values {status, loan_number[, amount]}.
 */
class ReturnAuditListener
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
        // No-op: ReturnService sudah menulis AuditLog 'loans.returned'.
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
        // No-op: FineService sudah menulis AuditLog 'fines.paid'.
    }

    public function onFineWaived(Fine $fine): void
    {
        // No-op: FineService sudah menulis AuditLog 'fines.waived'.
    }

    private function record(string $event, Loan|Fine $payload): void
    {
        // Anti-duplikat: ketiga event ini auditnya sudah ditulis service.
        if (in_array($event, ['loan.returned', 'fine.paid', 'fine.waived'], true)) {
            return;
        }

        $mapping = [
            'loan.overdue' => 'loans.overdue',
            'fine.issued' => 'fines.issued',
        ];

        if ($payload instanceof Fine) {
            $auditableType = Fine::class;
            $auditableId = $payload->getKey();
            $status = $payload->status instanceof \BackedEnum ? $payload->status->value : (string) $payload->status;
            $loan = $payload->relationLoaded('loan') ? $payload->loan : $payload->loan()->first();
            $newValues = array_filter([
                'status' => $status ?? null,
                'loan_number' => $loan?->loan_number,
                'amount' => $payload->amount,
            ], fn ($v) => $v !== null);
            // Aktor hanya tersedia untuk pembayaran (kolom paid_by = kasir).
            $userId = $event === 'fine.paid' ? $payload->paid_by : null;
        } else {
            $auditableType = Loan::class;
            $auditableId = $payload->getKey();
            $status = $payload->status instanceof \BackedEnum ? $payload->status->value : (string) $payload->status;
            $newValues = array_filter([
                'status' => $status ?? null,
                'loan_number' => $payload->loan_number,
            ], fn ($v) => $v !== null);
            // Aktor pengembalian tidak tersedia di event string — catat null.
            $userId = null;
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => $mapping[$event],
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => null,
            'new_values' => $newValues,
            'ip_address' => $this->requestIp(),
            'user_agent' => $this->requestUserAgent(),
        ]);
    }

    private function requestIp(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function requestUserAgent(): ?string
    {
        try {
            if (app()->runningInConsole()) {
                return null;
            }

            $agent = (string) request()->userAgent();

            return $agent === '' ? null : substr($agent, 0, 1000);
        } catch (\Throwable) {
            return null;
        }
    }
}
