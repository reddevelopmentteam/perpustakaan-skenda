<?php

namespace App\Filament\Widgets;

use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Tables\LoansTable;
use App\Models\Loan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ActionableLoansTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Peminjaman yang harus segera ditindaklanjuti')
            ->description('Menunggu persetujuan, jatuh tempo hari ini, dan terlambat.')
            ->query($this->getActionableQuery())
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor')
                    ->weight('medium'),
                TextColumn::make('student.name')
                    ->label('Siswa'),
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
            ->recordUrl(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record]))
            ->paginated(false);
    }

    protected function getActionableQuery(): Builder
    {
        return Loan::query()
            ->with(['student', 'items.book'])
            ->where(function (Builder $query): void {
                $query->where('status', LoanStatus::PENDING)
                    ->orWhere('status', LoanStatus::OVERDUE)
                    ->orWhere(fn (Builder $dueToday): Builder => $dueToday
                        ->whereDate('due_at', today())
                        ->whereIn('status', [LoanStatus::APPROVED, LoanStatus::BORROWED, LoanStatus::PARTIALLY_RETURNED]));
            })
            ->orderByRaw("case status when 'overdue' then 0 when 'pending' then 1 else 2 end")
            ->orderBy('due_at')
            ->limit(10);
    }
}
