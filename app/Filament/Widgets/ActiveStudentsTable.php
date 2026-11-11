<?php

namespace App\Filament\Widgets;

use App\Models\Student;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ActiveStudentsTable extends TableWidget
{
    protected static ?int $sort = 8;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Siswa Paling Aktif')
            ->description('10 siswa dengan peminjaman terbanyak.')
            ->query($this->getActiveQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Siswa')
                    ->weight('medium'),
                TextColumn::make('class')
                    ->label('Kelas'),
                TextColumn::make('loans_count')
                    ->label('Peminjaman')
                    ->numeric()
                    ->suffix(' kali'),
            ])
            ->paginated(false);
    }

    protected function getActiveQuery(): Builder
    {
        return Student::query()
            ->withCount('loans')
            ->orderByDesc('loans_count')
            ->limit(10);
    }
}
