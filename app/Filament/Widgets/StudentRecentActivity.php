<?php

namespace App\Filament\Widgets;

use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\Tables\LoansTable;
use App\Models\Loan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class StudentRecentActivity extends TableWidget
{
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isStudent() && $user->student !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Riwayat Terbaru')
            ->description('10 peminjaman terakhir milik saya.')
            ->query($this->getRecentQuery())
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor')
                    ->weight('medium'),
                TextColumn::make('items.book.title')
                    ->label('Buku Pertama')
                    ->getStateUsing(fn (Loan $record): ?string => $record->items->first()?->book?->title)
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => LoansTable::statusColor($state)),
                TextColumn::make('due_at')
                    ->label('Jatuh Tempo')
                    ->dateTime('d M Y')
                    ->placeholder('-'),
            ])
            ->paginated(false);
    }

    protected function getRecentQuery(): Builder
    {
        $studentId = auth()->user()?->student?->id;

        return Loan::query()
            ->with(['items.book'])
            ->where('student_id', $studentId)
            ->latest()
            ->limit(10);
    }
}
