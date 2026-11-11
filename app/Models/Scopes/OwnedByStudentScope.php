<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OwnedByStudentScope implements Scope
{
    /**
     * Restrict models with a direct `student_id` column to the
     * authenticated student's own rows.
     *
     * No-op for staff/super_admin, guests, and console (no auth),
     * so seeders keep working. A student without a `students` row
     * sees nothing instead of everything.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || ! $user->isStudent()) {
            return;
        }

        $studentId = $user->student?->id;

        if ($studentId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.student_id', $studentId);
    }
}
