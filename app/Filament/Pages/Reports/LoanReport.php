<?php

namespace App\Filament\Pages\Reports;

use App\Enums\LoanStatus;
use App\Models\Book;
use App\Models\Category;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Peminjaman — tabel loans with(student, items.book) + filter + export CSV.
 *
 * Pola: Page + `InteractsWithTable` dengan `EmbeddedTable` di `content()` —
 * pola yang sama dipakai `Filament\Resources\Pages\ListRecords`, sudah
 * terbukti render di Filament 5 (lihat slice ReportStock: BookReport).
 * Tidak perlu view Blade kustom maupun `InteractsWithTable` manual.
 *
 * Export CSV memakai `getTableQueryForExport()` (bawaan trait `HasRecords`)
 * sehingga filter + pencarian + sorting yang aktif di tabel OTOMATIS
 * menghormati ekspor — tanpa membaca `$this->tableFilters` manual dan tanpa
 * logika filter ganda. Batas 5000 baris ditangani exporter.
 */
class LoanReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Laporan Peminjaman';

    protected static ?string $title = 'Laporan Peminjaman';

    protected static ?string $slug = 'laporan-peminjaman';

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
            ->query(fn (): Builder => Loan::query()->with(['student', 'items.book']))
            ->defaultSort('borrowed_at', 'desc')
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('borrowed_at')
                    ->label('Tanggal Pinjam')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Loan $record): string => trim(
                        ($record->student?->student_number ?? '').' · '.($record->student?->class ?? ''),
                        ' ·'
                    ) ?: '-'),
                TextColumn::make('student.class')
                    ->label('Kelas')
                    ->toggleable(),
                TextColumn::make('books_list')
                    ->label('Buku')
                    ->state(fn (Loan $record): string => self::bookListText($record))
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => LoansTableStatusColor::color($state)),
                TextColumn::make('due_at')
                    ->label('Jatuh Tempo')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('returned_at')
                    ->label('Dikembalikan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('borrowed_at')
                    ->label('Tanggal Pinjam')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('borrowed_from')
                            ->label('Dari tanggal'),
                        \Filament\Forms\Components\DatePicker::make('borrowed_until')
                            ->label('Sampai tanggal'),
                    ])
                    ->query(
                        fn (Builder $query, array $data): Builder => $query
                            ->when($data['borrowed_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('borrowed_at', '>=', $date))
                            ->when($data['borrowed_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('borrowed_at', '<=', $date))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['borrowed_from'] ?? null) {
                            $indicators[] = 'Dari '.$data['borrowed_from'];
                        }

                        if ($data['borrowed_until'] ?? null) {
                            $indicators[] = 'Sampai '.$data['borrowed_until'];
                        }

                        return $indicators;
                    }),
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
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(
                        fn (Builder $query, array $data): Builder => $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q): Builder => $q->whereHas(
                                'items.book',
                                fn (Builder $bq): Builder => $bq->where('category_id', $data['value'])
                            )
                        )
                    ),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(LoanStatus::cases())->mapWithKeys(fn (LoanStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateHeading('Belum ada data peminjaman')
            ->emptyStateDescription('Data pinjaman yang tercatat akan tampil di sini.');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getTableQueryForExport()
            ->with(['student', 'items.book'])
            ->orderBy('borrowed_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursor()
            ->map(fn (Loan $loan): array => [
                $loan->loan_number,
                optional($loan->borrowed_at)->format('d/m/Y'),
                $loan->student?->student_number,
                $loan->student?->name,
                $loan->student?->class,
                $loan->items->map(fn ($item) => $item->book?->title ?? '-')->filter()->implode(', '),
                $loan->status instanceof LoanStatus ? $loan->status->label() : (string) $loan->status,
                optional($loan->due_at)->format('d/m/Y'),
                optional($loan->returned_at)->format('d/m/Y'),
            ])
            ->all();

        return ReportCsvExporter::export(
            'laporan-peminjaman-'.now()->format('Ymd-His').'.csv',
            ['No Pinjaman', 'Tanggal Pinjam', 'NIS', 'Nama Siswa', 'Kelas', 'Buku', 'Status', 'Jatuh Tempo', 'Tanggal Kembali'],
            $rows
        );
    }

    /**
     * Judul buku maksimal 3 + "+n lainnya" agar kolom tetap ringkas.
     */
    public static function bookListText(Loan $loan): string
    {
        $titles = $loan->items->map(fn ($item) => $item->book?->title ?? '-')->filter()->values();

        if ($titles->isEmpty()) {
            return '-';
        }

        $shown = $titles->take(3)->implode(', ');
        $rest = $titles->count() - 3;

        return $rest > 0 ? $shown.' (+'.$rest.' lainnya)' : $shown;
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
