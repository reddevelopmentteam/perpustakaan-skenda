<?php

namespace App\Filament\Widgets;

use App\Models\Book;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PopularBooksTable extends TableWidget
{
    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Buku Paling Sering Dipinjam')
            ->description('10 judul dengan peminjaman terbanyak.')
            ->query($this->getPopularQuery())
            ->columns([
                TextColumn::make('title')
                    ->label('Judul Buku')
                    ->weight('medium'),
                TextColumn::make('author')
                    ->label('Penulis')
                    ->toggleable(),
                TextColumn::make('loan_items_count')
                    ->label('Dipinjam')
                    ->numeric()
                    ->suffix(' kali'),
            ])
            ->paginated(false);
    }

    protected function getPopularQuery(): Builder
    {
        return Book::query()
            ->withCount('loanItems')
            ->orderByDesc('loan_items_count')
            ->limit(10);
    }
}
