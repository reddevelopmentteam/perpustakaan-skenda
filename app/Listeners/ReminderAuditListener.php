<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\Loan;

/**
 * Listener audit untuk event string `loan.reminder`.
 *
 * Kontrak dengan slice Core: service mem-fire
 * `Event::dispatch('loan.reminder', [['loan' => Loan, 'kind' => string, 'days' => int]])`.
 *
 * Toleransi payload (didokumentasikan, sama seperti ReminderNotificationListener):
 * handler `handle($payload, $extra = null)` menormalisasi dua bentuk —
 *  1. array asosiatif dengan key 'loan' (bentuk kontrak; 'kind'/'days' opsional),
 *  2. Loan langsung sebagai argumen pertama + array meta ['kind' => ..., 'days' => ...]
 *     sebagai argumen kedua (gaya `Event::dispatch('loan.reminder', [$loan, $meta])`).
 *
 * Menulis audit_logs action 'loans.reminder_sent', auditable = loan,
 * new_values {kind, days, loan_number}, user_id null (pengirim sistem/scheduler).
 * Tanpa guard dedupe — audit boleh mencatat tiap fire; dedupe ada di
 * service + notifikasi.
 */
class ReminderAuditListener
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

        $loan = $normalized['loan'];

        AuditLog::create([
            'user_id' => null,
            'action' => 'loans.reminder_sent',
            'auditable_type' => Loan::class,
            'auditable_id' => $loan->getKey(),
            'old_values' => null,
            'new_values' => [
                'kind' => $normalized['kind'],
                'days' => $normalized['days'],
                'loan_number' => $loan->loan_number,
            ],
            'ip_address' => $this->requestIp(),
            'user_agent' => $this->requestUserAgent(),
        ]);
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

    private function requestIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function requestUserAgent(): ?string
    {
        try {
            return request()->userAgent();
        } catch (\Throwable) {
            return null;
        }
    }
}
