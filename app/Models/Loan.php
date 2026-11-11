<?php

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['loan_number', 'student_id', 'status', 'requested_at', 'approved_at', 'borrowed_at', 'due_at', 'returned_at', 'approved_by', 'rejected_by', 'rejection_reason', 'notes', 'extension_count'])]
class Loan extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'borrowed_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(LoanExtension::class);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            LoanStatus::APPROVED,
            LoanStatus::BORROWED,
            LoanStatus::PARTIALLY_RETURNED,
            LoanStatus::OVERDUE,
        ]);
    }
}
