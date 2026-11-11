<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanExtension;
use App\Models\Student;

/**
 * SATU listener untuk semua event string loan.
 *
 * Kontrak dengan slice Service: event memakai string-based dengan payload
 * tunggal: Loan untuk loan.requested/approved/rejected/borrowed/cancelled,
 * dan LoanExtension (ambil loan via ->loan) untuk
 * loan.extension_requested/approved/rejected.
 *
 * Didukung dua gaya registrasi: 8x Event::listen eksplisit (metode on*)
 * maupun wildcard 'loan.*' (metode handle). Service mem-fire event 1x di
 * dalam transaksi.
 *
 * Batasan (lihat Tahap 11): listener menulis 1x per event tanpa dedupe.
 * Dedupe berbasis kolom JSON data rapuh di SQLite sehingga sengaja di-SKIP.
 */
class LoanAuditListener
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
        $mapping = [
            'loan.requested' => 'loans.requested',
            'loan.approved' => 'loans.approved',
            'loan.rejected' => 'loans.rejected',
            'loan.borrowed' => 'loans.borrowed',
            'loan.cancelled' => 'loans.cancelled',
            'loan.extension_requested' => 'loan_extensions.requested',
            'loan.extension_approved' => 'loan_extensions.approved',
            'loan.extension_rejected' => 'loan_extensions.rejected',
        ];

        if (! isset($mapping[$event])) {
            return;
        }

        if ($payload instanceof LoanExtension) {
            $auditableType = LoanExtension::class;
            $auditableId = $payload->getKey();
            $loan = $payload->relationLoaded('loan') ? $payload->loan : $payload->loan()->first();
            $status = $payload->status instanceof \BackedEnum ? $payload->status->value : (string) $payload->status;
            $loanNumber = $loan?->loan_number;
            // Aktor per event: requested -> peminta; approved/rejected -> penyetuju.
            $userId = $event === 'loan.extension_requested'
                ? $payload->requested_by
                : $payload->approved_by;
        } else {
            $auditableType = Loan::class;
            $auditableId = $payload->getKey();
            $loanNumber = $payload->loan_number;
            $status = $payload->status instanceof \BackedEnum ? $payload->status->value : (string) $payload->status;
            $userId = $payload->approved_by ?? $payload->rejected_by;

            if ($userId === null && $event === 'loan.requested') {
                $student = $payload->relationLoaded('student')
                    ? $payload->student
                    : $payload->student()->first();
                $userId = $student instanceof Student ? $student->user_id : null;
            }
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => $mapping[$event],
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => null,
            'new_values' => array_filter([
                'status' => $status ?? null,
                'loan_number' => $loanNumber,
            ], fn ($v) => $v !== null),
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
