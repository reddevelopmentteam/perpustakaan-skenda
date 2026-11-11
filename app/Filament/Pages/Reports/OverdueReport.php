<?php

namespace App\Filament\Pages\Reports;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Models\Book;
use App\Models\Loan;
use App\Models\Student;
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
 * Laporan Keterlambatan — loans berstatus OVERDUE + hari terlambat
 * (diff now - due, maks 0) + total denda unpaid aktual (SUM fines).
 * Pola sama seperti LoanReport.
 */
class OverdueReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Laporan Keterlambatan';

    protected static ?string $title = 'Laporan Keterlambatan';

    protected static ?string $slug = 'laporan-keterlambatan';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('viewAny', Loan::class) && ($user->isStaff() || $user->isSuperAdmin());
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
            ->query(
                fn (): Builder => Loan::query()
                    ->with(['student', 'items.book', 'fines'])
                    ->where('status', LoanStatus::OVERDUE)
            )
            ->defaultSort('due_at', 'asc')
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->toggleable(),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->description(fn (Loan $record): string => trim(
                        ($record->student?->student_number ?? '').' · '.($record->student?->class ?? ''),
                        ' ·'
                    ) ?: '-'),
                TextColumn::make('books_list')
                    ->label('Buku')
                    ->state(fn (Loan $record): string => LoanReport::bookListText($record))
                    ->wrap(),
                TextColumn::make('due_at')
                    ->label('Jatuh Tempo')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('late_days')
                    ->label('Hari Terlambat')
                    ->state(fn (Loan $record): int => self::lateDays($record))
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('danger'),
                TextColumn::make('unpaid_fine')
                    ->label('Denda (Rp)')
                    ->state(fn (Loan $record): int => self::unpaidFine($record))
                    ->numeric()
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.')),
            ])
            ->filters([
                SelectFilter::make('student')
                    ->label('Siswa')
                    ->relationship('student', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('kelas')
                    ->label('Kelas')
                    ->options(fn (): array => Student::query()->distinct()->orderBy('class')->pluck('class', 'class')->all())
                    ->query(
                        fn (Builder $query, array $data): Builder => $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => $q->whereHas('student', fn (Builder $sq): Builder => $sq->where('class', $data['value']))
                        )
                    ),
                SelectFilter::make('book')
                    ->label('Buku')
                    ->options(fn (): array => Book::query()->orderBy('title')->limit(100)->pluck('title', 'id')->all())
                    ->searchable()
                    ->query(
                        fn (Builder $query, array $data): Builder => $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => $q->whereHas('items', fn (Builder $iq): Builder => $iq->where('book_id', $data['value']))
                        )
                    ),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateIcon('heroicon-m-exclamation-circle')
            ->emptyStateHeading('Tidak ada keterlambatan')
            ->emptyStateDescription('Semua pinjaman saat ini masih tepat waktu.');
    }

    public static function lateDays(Loan $loan): int
    {
        if ($loan->due_at === null) {
            return 0;
        }

        return max(0, (int) $loan->due_at->startOfDay()->diffInDays(now()->startOfDay(), false));
    }

    /**
     * Total denda BELUM DIBAYAR aktual (SUM fines unpaid) — data aktual,
     * bukan hitungan ulang tarif × hari.
     */
    public static function unpaidFine(Loan $loan): int
    {
        if ($loan->relationLoaded('fines')) {
            return (int) $loan->fines->where('status', FineStatus::UNPAID)->sum('amount');
        }

        return (int) $loan->fines()->where('status', FineStatus::UNPAID)->sum('amount');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getTableQueryForExport()
            ->with(['student', 'items.book', 'fines'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->cursor()
            ->map(fn (Loan $loan): array => [
                $loan->student?->student_number,
                $loan->student?->name,
                $loan->student?->class,
                $loan->items->map(fn ($item) => $item->book?->title ?? '-')->filter()->implode(', '),
                optional($loan->due_at)->format('d/m/Y'),
                (string) self::lateDays($loan),
                (string) self::unpaidFine($loan),
            ])
            ->all();

        return ReportCsvExporter::export(
            'laporan-keterlambatan-'.now()->format('Ymd-His').'.csv',
            ['NIS', 'Nama', 'Kelas', 'Buku', 'Jatuh Tempo', 'Hari Terlambat', 'Denda (Rp)'],
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
