<?php

namespace App\Filament\Pages\Reports;

use App\Models\Book;
use App\Services\Reports\ReportCsvExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan buku: tabel books with(category, copies),
 * filter kategori + jenis ketersediaan, export CSV.
 */
class BookReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Laporan Buku';

    protected static ?string $title = 'Laporan Buku';

    protected static ?string $slug = 'laporan-buku';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('viewAny', Book::class) && ($user->isStaff() || $user->isSuperAdmin());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Book::query()->with(['category'])->withCount([
                'copies',
                'loanItems as loan_items_count',
            ]))
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('author')
                    ->label('Penulis')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_stock')
                    ->label('Tersedia')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (Book $record): string => $record->available_stock > 0 ? 'success' : 'danger'),
                TextColumn::make('borrowed_stock')
                    ->label('Dipinjam')
                    ->state(fn (Book $record): int => max(0, $record->stock - $record->available_stock))
                    ->numeric(),
                TextColumn::make('loan_items_count')
                    ->label('Total Pinjam')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('jenis')
                    ->label('Jenis')
                    ->options([
                        'tersedia' => 'Tersedia',
                        'dipinjam' => 'Dipinjam Semua',
                        'habis' => 'Habis',
                        'jarang' => 'Jarang Dipinjam (< 3)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'tersedia' => $query->where('available_stock', '>', 0),
                            'dipinjam' => $query->where('available_stock', '<=', 0)->where('stock', '>', 0),
                            'habis' => $query->where('stock', '<=', 0),
                            'jarang' => $query->whereHas('loanItems', null, '<', 3),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateHeading('Belum ada buku')
            ->emptyStateDescription('Data buku yang tercatat akan tampil di sini.');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getTableQueryForExport()
            ->with(['category'])
            ->withCount(['loanItems as loan_items_count'])
            ->orderBy('title')
            ->cursor()
            ->map(fn (Book $book): array => [
                $book->title,
                $book->author,
                $book->category?->name ?? '-',
                (string) ((int) $book->stock),
                (string) ((int) $book->available_stock),
                (string) max(0, (int) $book->stock - (int) $book->available_stock),
                (string) ((int) ($book->loan_items_count ?? 0)),
            ])
            ->all();

        $filename = 'laporan-buku-'.now()->format('Ymd-His').'.csv';

        return ReportCsvExporter::export(
            $filename,
            ['Judul', 'Penulis', 'Kategori', 'Stok', 'Tersedia', 'Dipinjam', 'Total Pinjam'],
            $rows
        );
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
