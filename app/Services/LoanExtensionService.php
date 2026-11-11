<?php

namespace App\Services;

use App\Enums\ExtensionStatus;
use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Exceptions\LoanException;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanExtension;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Pengajuan + approval perpanjangan peminjaman (Tahap 7, §18 perpus.md).
 * Semua method statis.
 *
 * - `due_at` TIDAK berubah saat pengajuan; baru maju saat approval.
 * - Event string-based dengan payload `[LoanExtension]`
 *   (akses loan via `$extension->loan`):
 *   `loan.extension_requested`, `loan.extension_approved`, `loan.extension_rejected`.
 */
class LoanExtensionService
{
    /** Status loan yang boleh diperpanjang. */
    private static function assertLoanExtendable(Loan $loan): void
    {
        if (! in_array($loan->status, [
            LoanStatus::BORROWED,
            LoanStatus::OVERDUE,
            LoanStatus::PARTIALLY_RETURNED,
        ], true)) {
            throw new LoanException('Perpanjangan hanya dapat diajukan untuk pinjaman yang sedang aktif.');
        }
    }

    public static function request(Loan $loan, User $by, ?string $reason = null): LoanExtension
    {
        self::assertLoanExtendable($loan);

        $isStaffLike = $by->isStaff() || $by->isSuperAdmin();

        if (! $isStaffLike) {
            $ownerId = $by->student?->id;

            if ($ownerId === null || $ownerId !== $loan->student_id) {
                throw new LoanException('Perpanjangan hanya dapat diajukan oleh siswa pemilik peminjaman.');
            }

            if (! $loan->student->isBorrowable()) {
                throw new LoanException('Siswa tidak aktif atau diblokir sehingga tidak dapat mengajukan perpanjangan.');
            }
        }

        $maxExtensions = (int) Setting::get('max_extensions', '1');

        if ($loan->extension_count >= $maxExtensions) {
            throw new LoanException("Perpanjangan telah mencapai batas maksimal {$maxExtensions} kali.");
        }

        $hasPending = LoanExtension::query()
            ->where('loan_id', $loan->id)
            ->where('status', ExtensionStatus::PENDING->value)
            ->exists();

        if ($hasPending) {
            throw new LoanException('Masih ada pengajuan perpanjangan yang menunggu persetujuan untuk pinjaman ini.');
        }

        $bookIds = $loan->items()->pluck('book_id');

        $reservedByOther = Reservation::query()
            ->whereIn('book_id', $bookIds)
            ->where('student_id', '!=', $loan->student_id)
            ->active()
            ->exists();

        if ($reservedByOther) {
            throw new LoanException('Buku dalam pinjaman ini sedang direservasi siswa lain sehingga tidak dapat diperpanjang.');
        }

        return DB::transaction(function () use ($loan, $by, $reason) {
            $extension = LoanExtension::create([
                'loan_id' => $loan->id,
                'requested_by' => $by->id,
                'old_due_at' => $loan->due_at,
                'new_due_at' => null,
                'status' => ExtensionStatus::PENDING,
                'reason' => $reason,
            ]);

            AuditLog::create([
                'user_id' => $by->id,
                'action' => 'loan_extensions.requested',
                'auditable_type' => LoanExtension::class,
                'auditable_id' => $extension->id,
                'new_values' => ['loan_id' => $loan->id, 'status' => ExtensionStatus::PENDING->value],
            ]);

            Event::dispatch('loan.extension_requested', [$extension]);

            return $extension->refresh();
        });
    }

    public static function approve(LoanExtension $extension, User $staff): Loan
    {
        if ($extension->status !== ExtensionStatus::PENDING) {
            throw new LoanException('Hanya pengajuan perpanjangan yang menunggu persetujuan yang dapat disetujui.');
        }

        $loan = $extension->loan;
        self::assertLoanExtendable($loan);

        return DB::transaction(function () use ($extension, $staff, $loan) {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            $extensionDays = (int) Setting::get('extension_days', '7');
            $oldDueAt = $lockedLoan->due_at ?? now();
            $newDueAt = (clone $oldDueAt)->addDays($extensionDays);

            $lockedLoan->update([
                'due_at' => $newDueAt,
                'extension_count' => $lockedLoan->extension_count + 1,
            ]);

            $lockedLoan->items()
                ->whereIn('status', [LoanItemStatus::BORROWED->value, LoanItemStatus::OVERDUE->value])
                ->update(['due_at' => $newDueAt]);

            $extension->update([
                'status' => ExtensionStatus::APPROVED,
                'old_due_at' => $oldDueAt,
                'new_due_at' => $newDueAt,
                'approved_by' => $staff->id,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loan_extensions.approved',
                'auditable_type' => LoanExtension::class,
                'auditable_id' => $extension->id,
                'old_values' => ['status' => ExtensionStatus::PENDING->value],
                'new_values' => [
                    'status' => ExtensionStatus::APPROVED->value,
                    'new_due_at' => $newDueAt->toDateTimeString(),
                ],
            ]);

            Event::dispatch('loan.extension_approved', [$extension]);

            return $lockedLoan->refresh();
        });
    }

    public static function reject(LoanExtension $extension, User $staff, ?string $reason = null): LoanExtension
    {
        if ($extension->status !== ExtensionStatus::PENDING) {
            throw new LoanException('Hanya pengajuan perpanjangan yang menunggu persetujuan yang dapat ditolak.');
        }

        return DB::transaction(function () use ($extension, $staff, $reason) {
            $extension->update([
                'status' => ExtensionStatus::REJECTED,
                'approved_by' => $staff->id,
                'reason' => $reason ?? $extension->reason,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'loan_extensions.rejected',
                'auditable_type' => LoanExtension::class,
                'auditable_id' => $extension->id,
                'old_values' => ['status' => ExtensionStatus::PENDING->value],
                'new_values' => ['status' => ExtensionStatus::REJECTED->value],
            ]);

            Event::dispatch('loan.extension_rejected', [$extension]);

            return $extension->refresh();
        });
    }
}
