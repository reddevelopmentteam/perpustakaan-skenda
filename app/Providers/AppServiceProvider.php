<?php

namespace App\Providers;

use App\Listeners\ReminderAuditListener;
use App\Listeners\ReminderNotificationListener;
use App\Listeners\PromoteReservationOnReturn;
use App\Listeners\ReservationAuditListener;
use App\Listeners\ReservationNotificationListener;
use App\Listeners\LoanAuditListener;
use App\Listeners\LoanNotificationListener;
use App\Listeners\ReturnAuditListener;
use App\Listeners\ReturnNotificationListener;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Category;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanExtension;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\BookCopyPolicy;
use App\Policies\BookPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\FinePolicy;
use App\Policies\LoanExtensionPolicy;
use App\Policies\LoanItemPolicy;
use App\Policies\LoanPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\SettingPolicy;
use App\Policies\StudentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Book::class, BookPolicy::class);
        Gate::policy(BookCopy::class, BookCopyPolicy::class);
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(LoanItem::class, LoanItemPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(Fine::class, FinePolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(LoanExtension::class, LoanExtensionPolicy::class);
        Event::listen('loan.requested', [LoanAuditListener::class, 'onRequested']);
        Event::listen('loan.approved', [LoanAuditListener::class, 'onApproved']);
        Event::listen('loan.rejected', [LoanAuditListener::class, 'onRejected']);
        Event::listen('loan.borrowed', [LoanAuditListener::class, 'onBorrowed']);
        Event::listen('loan.cancelled', [LoanAuditListener::class, 'onCancelled']);
        Event::listen('loan.extension_requested', [LoanAuditListener::class, 'onExtensionRequested']);
        Event::listen('loan.extension_approved', [LoanAuditListener::class, 'onExtensionApproved']);
        Event::listen('loan.extension_rejected', [LoanAuditListener::class, 'onExtensionRejected']);
        Event::listen('loan.requested', [LoanNotificationListener::class, 'onRequested']);
        Event::listen('loan.approved', [LoanNotificationListener::class, 'onApproved']);
        Event::listen('loan.rejected', [LoanNotificationListener::class, 'onRejected']);
        Event::listen('loan.borrowed', [LoanNotificationListener::class, 'onBorrowed']);
        Event::listen('loan.cancelled', [LoanNotificationListener::class, 'onCancelled']);
        Event::listen('loan.extension_requested', [LoanNotificationListener::class, 'onExtensionRequested']);
        Event::listen('loan.extension_approved', [LoanNotificationListener::class, 'onExtensionApproved']);
        Event::listen('loan.extension_rejected', [LoanNotificationListener::class, 'onExtensionRejected']);
        Event::listen('loan.returned', [ReturnAuditListener::class, 'onReturned']);
        Event::listen('loan.overdue', [ReturnAuditListener::class, 'onOverdue']);
        Event::listen('fine.issued', [ReturnAuditListener::class, 'onFineIssued']);
        Event::listen('fine.paid', [ReturnAuditListener::class, 'onFinePaid']);
        Event::listen('fine.waived', [ReturnAuditListener::class, 'onFineWaived']);
        Event::listen('loan.returned', [ReturnNotificationListener::class, 'onReturned']);
        Event::listen('loan.overdue', [ReturnNotificationListener::class, 'onOverdue']);
        Event::listen('fine.issued', [ReturnNotificationListener::class, 'onFineIssued']);
        Event::listen('fine.paid', [ReturnNotificationListener::class, 'onFinePaid']);
        Event::listen('fine.waived', [ReturnNotificationListener::class, 'onFineWaived']);
        Event::listen('reservation.created', [ReservationAuditListener::class, 'onCreated']);
        Event::listen('reservation.available', [ReservationAuditListener::class, 'onAvailable']);
        Event::listen('reservation.fulfilled', [ReservationAuditListener::class, 'onFulfilled']);
        Event::listen('reservation.cancelled', [ReservationAuditListener::class, 'onCancelled']);
        Event::listen('reservation.expired', [ReservationAuditListener::class, 'onExpired']);
        Event::listen('reservation.created', [ReservationNotificationListener::class, 'onCreated']);
        Event::listen('reservation.available', [ReservationNotificationListener::class, 'onAvailable']);
        Event::listen('reservation.fulfilled', [ReservationNotificationListener::class, 'onFulfilled']);
        Event::listen('reservation.cancelled', [ReservationNotificationListener::class, 'onCancelled']);
        Event::listen('reservation.expired', [ReservationNotificationListener::class, 'onExpired']);
        Event::listen('loan.returned', [PromoteReservationOnReturn::class, 'handle']);
        Event::listen('loan.reminder', [ReminderNotificationListener::class, 'handle']);
        Event::listen('loan.reminder', [ReminderAuditListener::class, 'handle']);
    }
}
