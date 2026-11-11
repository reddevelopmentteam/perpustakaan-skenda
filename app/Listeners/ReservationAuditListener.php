<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\Reservation;

/**
 * SATU listener audit untuk semua event string reservasi Tahap 9.
 *
 * Kontrak dengan slice Service: event memakai string-based dengan payload
 * tunggal Reservation: 'reservation.created'/'reservation.available'/
 * 'reservation.fulfilled'/'reservation.cancelled'/'reservation.expired'.
 * Service mem-fire event 1x di dalam transaksi.
 *
 * Peta transisi status yang divalidasi manual di ReservationService
 * (enum ReservationStatus TIDAK punya canTransitionTo):
 * waiting -> [available, cancelled, expired],
 * available -> [fulfilled, cancelled, expired],
 * fulfilled/expired/cancelled -> [] (terminal).
 *
 * Aktor (user_id) tidak ada di payload event string — selalu null,
 * konsisten dengan Tahap 8 (ReturnAuditListener mencatat null untuk
 * loan.overdue/fines.issued). new_values: {status, reservation_number,
 * queue_position}.
 *
 * Didukung dua gaya registrasi: 5x Event::listen eksplisit (metode on*)
 * maupun wildcard 'reservation.*' (metode handle).
 */
class ReservationAuditListener
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
        $mapping = [
            'reservation.created' => 'reservations.created',
            'reservation.available' => 'reservations.available',
            'reservation.fulfilled' => 'reservations.fulfilled',
            'reservation.cancelled' => 'reservations.cancelled',
            'reservation.expired' => 'reservations.expired',
        ];

        if (! isset($mapping[$event])) {
            return;
        }

        $status = $payload->status instanceof \BackedEnum ? $payload->status->value : (string) $payload->status;

        AuditLog::create([
            // Aktor tidak tersedia di payload event string — catat null.
            'user_id' => null,
            'action' => $mapping[$event],
            'auditable_type' => Reservation::class,
            'auditable_id' => $payload->getKey(),
            'old_values' => null,
            'new_values' => array_filter([
                'status' => $status ?? null,
                'reservation_number' => $payload->reservation_number,
                'queue_position' => $payload->queue_position,
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
