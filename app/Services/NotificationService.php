<?php

namespace App\Services;

use App\Notifications\FineNotification;
use App\Notifications\LoanNotification;
use App\Notifications\ReminderNotification;
use App\Notifications\ReservationNotification;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Pusat pengiriman notifikasi (§17, §34).
 *
 * Semua notifikasi database melalui channel 'database' dan diantrekan
 * (ShouldQueue) agar tidak memblokir transaksi inti. Mendukung penambahan
 * channel email, WhatsApp, Telegram di masa depan tanpa mengubah caller.
 */
class NotificationService
{
    /**
     * Kirim notifikasi peminjaman ke satu user.
     */
    public static function loan(User $user, Loan $loan, string $event, string $message, string $url): void
    {
        $user->notify(new LoanNotification($loan, $event, $message, $url));
    }

    /**
     * Kirim notifikasi reservasi ke satu user.
     */
    public static function reservation(User $user, Reservation $reservation, string $event, string $message, string $url): void
    {
        $user->notify(new ReservationNotification($reservation, $event, $message, $url));
    }

    /**
     * Kirim notifikasi denda ke satu user.
     */
    public static function fine(User $user, Fine $fine, string $event, string $message, string $url): void
    {
        $user->notify(new FineNotification($fine, $event, $message, $url));
    }

    /**
     * Kirim notifikasi pengingat jatuh tempo ke satu user.
     */
    public static function reminder(User $user, Loan $loan, int $daysLate, string $message): void
    {
        $user->notify(new ReminderNotification($loan, $daysLate, $message));
    }

    /**
     * Kirim notifikasi ke banyak user sekaligus.
     *
     * @param  list<User>  $users
     * @param  Notification  $notification
     */
    public static function broadcast(array $users, Notification $notification): void
    {
        foreach ($users as $user) {
            $user->notify($notification);
        }
    }
}
