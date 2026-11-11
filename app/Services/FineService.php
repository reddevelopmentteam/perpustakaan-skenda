<?php

namespace App\Services;

use App\Enums\FineStatus;
use App\Exceptions\LoanException;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Perhitungan + lifecycle denda keterlambatan (Tahap 8, §16 perpus.md).
 * Semua method statis.
 *
 * Aturan mengikat:
 * - `calculate()` murni (tanpa tulis DB): tarif/grace/max dibaca dari
 *   `settings` (`fine_per_day`, `grace_period_days`, `max_fine`).
 * - `max_fine` null (tidak ada baris setting) = tanpa batas.
 * - Denda menyimpan `amount` + `fine_rate_snapshot` sehingga kebal
 *   terhadap perubahan tarif di tengah pinjaman aktif (AGENTS.md §3.8).
 * - Satu loan hanya boleh punya SATU denda UNPAID (`issueForLoan()`
 *   idempotent: buat sekali, hitung ulang sesudahnya).
 * - Event string-based (payload `[Fine]`): `fine.issued`, `fine.paid`,
 *   `fine.waived` — didengar slice Events (notifikasi); audit ditulis
 *   langsung di service ini (konsisten dengan LoanService Tahap 7).
 */
class FineService
{
    /**
     * Hitung denda secara murni (tanpa tulis DB).
     *
     * @return array{late_days:int, amount:int, rate:int}
     */
    public static function calculate(Loan $loan, ?Carbon $at = null): array
    {
        $rate = (int) Setting::get('fine_per_day', '1000');
        $grace = (int) Setting::get('grace_period_days', '0');

        $at ??= now();
        $basis = $loan->due_at;

        // Hari keterlambatan = hari kalender penuh (floor selisih/24 jam).
        if ($basis === null || $at->lessThanOrEqualTo($basis)) {
            $days = 0;
        } else {
            $days = (int) $basis->diffInDays($at);
        }

        $late = max(0, $days - $grace);
        $amount = $late * $rate;

        // max_fine null (tidak ada baris setting) = tanpa batas.
        $maxRaw = Setting::get('max_fine');

        if ($maxRaw !== null && $maxRaw !== '' && is_numeric($maxRaw)) {
            $amount = min($amount, (int) $maxRaw);
        }

        return ['late_days' => $late, 'amount' => $amount, 'rate' => $rate];
    }

    /**
     * Terbitkan (atau hitung ulang) denda berjalan untuk satu loan.
     * Idempotent: loan tepat waktu -> null; sudah ada UNPAID -> update,
     * belum ada -> buat satu baris baru.
     */
    public static function issueForLoan(Loan $loan, ?Carbon $at = null): ?Fine
    {
        $calc = self::calculate($loan, $at);

        if ($calc['late_days'] <= 0) {
            return null;
        }

        $fine = Fine::query()
            ->where('loan_id', $loan->id)
            ->where('status', FineStatus::UNPAID->value)
            ->first();

        if ($fine !== null) {
            $fine->update([
                'late_days' => $calc['late_days'],
                'amount' => $calc['amount'],
                'fine_rate_snapshot' => $calc['rate'],
            ]);

            Event::dispatch('fine.issued', [$fine]);

            return $fine->refresh();
        }

        $fine = Fine::create([
            'loan_id' => $loan->id,
            'student_id' => $loan->student_id,
            'amount' => $calc['amount'],
            'late_days' => $calc['late_days'],
            'fine_rate_snapshot' => $calc['rate'],
            'status' => FineStatus::UNPAID,
        ]);

        Event::dispatch('fine.issued', [$fine]);

        return $fine->refresh();
    }

    /**
     * Bayar denda. Hanya dari status UNPAID.
     */
    public static function pay(Fine $fine, User $cashier, ?string $notes = null): Fine
    {
        if ($fine->status !== FineStatus::UNPAID) {
            throw new LoanException(
                $fine->status === FineStatus::PAID
                    ? 'Denda sudah dibayar.'
                    : 'Denda sudah dibebaskan.'
            );
        }

        return DB::transaction(function () use ($fine, $cashier, $notes) {
            $fine->update([
                'status' => FineStatus::PAID,
                'paid_at' => now(),
                'paid_by' => $cashier->id,
                'notes' => $notes,
            ]);

            AuditLog::create([
                'user_id' => $cashier->id,
                'action' => 'fines.paid',
                'auditable_type' => Fine::class,
                'auditable_id' => $fine->id,
                'old_values' => ['status' => FineStatus::UNPAID->value],
                'new_values' => [
                    'status' => FineStatus::PAID->value,
                    'amount' => $fine->amount,
                    'notes' => $notes,
                ],
            ]);

            Event::dispatch('fine.paid', [$fine]);

            return $fine->refresh();
        });
    }

    /**
     * Bebaskan denda. Hanya dari status UNPAID.
     */
    public static function waive(Fine $fine, User $staff, ?string $notes = null): Fine
    {
        if ($fine->status !== FineStatus::UNPAID) {
            throw new LoanException(
                $fine->status === FineStatus::PAID
                    ? 'Denda sudah dibayar.'
                    : 'Denda sudah dibebaskan.'
            );
        }

        return DB::transaction(function () use ($fine, $staff, $notes) {
            $fine->update([
                'status' => FineStatus::WAIVED,
                'notes' => $notes,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'fines.waived',
                'auditable_type' => Fine::class,
                'auditable_id' => $fine->id,
                'old_values' => ['status' => FineStatus::UNPAID->value],
                'new_values' => [
                    'status' => FineStatus::WAIVED->value,
                    'amount' => $fine->amount,
                    'notes' => $notes,
                ],
            ]);

            Event::dispatch('fine.waived', [$fine]);

            return $fine->refresh();
        });
    }
}
