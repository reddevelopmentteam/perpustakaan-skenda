<?php

namespace App\Filament\Pages\Reports;

use App\Enums\LoanStatus;
use App\Models\Loan;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Pengembalian — loans dengan returned_at terisi + ketepatan
 * (tepat bila returned_at <= due_at). Pola sama seperti LoanReport.
 */
class ReturnReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Laporan Pengembalian';

    protected static ?string $title = 'Laporan Pengembalian';

    protected static ?string $slug = 'laporan-pengembalian';

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
            ->query(fn (): Builder => Loan::query()->with(['student', 'items.book'])->whereNotNull('returned_at'))
            ->defaultSort('returned_at', 'desc')
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
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
                TextColumn::make('borrowed_at')
                    ->label('Dipinjam')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('due_at')
                    ->label('Jatuh Tempo')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('returned_at')
                    ->label('Dikembalikan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('ketepatan')
                    ->label('Ketepatan')
                    ->badge()
                    ->state(fn (Loan $record): string => self::isOnTime($record) ? 'Tepat Waktu' : 'Terlambat')
                    ->color(fn (Loan $record): string => self::isOnTime($record) ? 'success' : 'danger'),
            ])
            ->filters([
                Filter::make('returned_at')
                    ->label('Periode Pengembalian')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('returned_from')
                            ->label('Dari tanggal'),
                        \Filament\Forms\Components\DatePicker::make('returned_until')
                            ->label('Sampai tanggal'),
                    ])
                    ->query(
                        fn (Builder $query, array $data): Builder => $query
                            ->when($data['returned_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('returned_at', '>=', $date))
                            ->when($data['returned_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('returned_at', '<=', $date))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['returned_from'] ?? null) {
                            $indicators[] = 'Dari '.$data['returned_from'];
                        }

                        if ($data['returned_until'] ?? null) {
                            $indicators[] = 'Sampai '.$data['returned_until'];
                        }

                        return $indicators;
                    }),
                SelectFilter::make('ketepatan')
                    ->label('Ketepatan')
                    ->options(['tepat' => 'Tepat Waktu', 'terlambat' => 'Terlambat'])
                    ->query(
                        fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                            'tepat' => $query->whereColumn('returned_at', '<=', 'due_at'),
                            'terlambat' => $query->whereColumn('returned_at', '>', 'due_at'),
                            default => $query,
                        }
                    ),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateHeading('Belum ada data pengembalian')
            ->emptyStateDescription('Pinjaman yang sudah dikembalikan akan tampil di sini.');
    }

    public static function isOnTime(Loan $loan): bool
    {
        if ($loan->returned_at === null) {
            return true;
        }

        if ($loan->due_at === null) {
            return true;
        }

        return $loan->returned_at->lte($loan->due_at);
    }

    /**
     * Hari terlambat = selisih hari returned - due, minimal 0.
     */
    public static function lateDays(Loan $loan): int
    {
        if ($loan->returned_at === null || $loan->due_at === null) {
            return 0;
        }

        return max(0, (int) $loan->due_at->startOfDay()->diffInDays($loan->returned_at->startOfDay(), false));
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getTableQueryForExport()
            ->with(['student', 'items.book'])
            ->orderBy('returned_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursor()
            ->map(fn (Loan $loan): array => [
                $loan->loan_number,
                $loan->student?->student_number,
                $loan->student?->name,
                $loan->items->map(fn ($item) => $item->book?->title ?? '-')->filter()->implode(', '),
                optional($loan->borrowed_at)->format('d/m/Y'),
                optional($loan->due_at)->format('d/m/Y'),
                optional($loan->returned_at)->format('d/m/Y'),
                self::isOnTime($loan) ? 'Tepat Waktu' : 'Terlambat',
                (string) self::lateDays($loan),
                $loan->status instanceof LoanStatus ? $loan->status->label() : (string) $loan->status,
            ])
            ->all();

        return ReportCsvExporter::export(
            'laporan-pengembalian-'.now()->format('Ymd-His').'.csv',
            ['No Pinjaman', 'NIS', 'Nama Siswa', 'Buku', 'Tanggal Pinjam', 'Jatuh Tempo', 'Tanggal Kembali', 'Ketepatan', 'Hari Terlambat', 'Status'],
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
