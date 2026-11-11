<?php

namespace App\Filament\Pages\Reports;

use App\Enums\FineStatus;
use App\Models\Fine;
use App\Services\Reports\ReportCsvExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
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
 * Laporan denda: tabel fines with(loan, student),
 * filter periode + status, ringkasan total unpaid/paid per filter.
 */
class FineReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 33;

    protected static ?string $navigationLabel = 'Laporan Denda';

    protected static ?string $title = 'Laporan Denda';

    protected static ?string $slug = 'laporan-denda';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('viewAny', Fine::class) && ($user->isStaff() || $user->isSuperAdmin());
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
            ->query(fn (): Builder => Fine::query()->with(['loan', 'student', 'cashier'])->latest())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('loan.loan_number')
                    ->label('No. Pinjaman')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('student.student_number')
                    ->label('NIS')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->description(fn (Fine $record): ?string => $record->student?->class),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable()
                    ->summarize(Sum::make()->label('Total')->money('IDR')),
                TextColumn::make('late_days')
                    ->label('Hari')
                    ->numeric()
                    ->suffix(' hari')
                    ->sortable(),
                TextColumn::make('fine_rate_snapshot')
                    ->label('Tarif')
                    ->money('IDR')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof FineStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        FineStatus::UNPAID => 'danger',
                        FineStatus::PAID => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('cashier.name')
                    ->label('Kasir')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('paid_at')
                    ->label('Tanggal Bayar')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(FineStatus::cases())->mapWithKeys(fn (FineStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('periode')
                    ->label('Periode Dibuat')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('dari')
                            ->label('Dari tanggal'),
                        \Filament\Forms\Components\DatePicker::make('sampai')
                            ->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['dari'] ?? null) {
                            $indicators[] = 'Dari '.$data['dari'];
                        }

                        if ($data['sampai'] ?? null) {
                            $indicators[] = 'Sampai '.$data['sampai'];
                        }

                        return $indicators;
                    }),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateHeading('Belum ada denda')
            ->emptyStateDescription('Data denda yang tercatat akan tampil di sini.');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->getTableQueryForExport()
            ->with(['loan', 'student'])
            ->orderBy('created_at')
            ->cursor()
            ->map(fn (Fine $fine): array => [
                $fine->loan?->loan_number ?? '-',
                $fine->student?->student_number ?? '-',
                $fine->student?->name ?? '-',
                (string) ((int) $fine->amount),
                (string) ((int) $fine->late_days),
                (string) ((int) $fine->fine_rate_snapshot),
                $fine->status instanceof FineStatus ? $fine->status->label() : (string) $fine->status,
                $fine->paid_at?->format('d-m-Y H:i') ?? '-',
            ])
            ->all();

        $filename = 'laporan-denda-'.now()->format('Ymd-His').'.csv';

        return ReportCsvExporter::export(
            $filename,
            ['No Pinjaman', 'NIS', 'Nama', 'Nominal', 'Hari', 'Tarif', 'Status', 'Tanggal Bayar'],
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
