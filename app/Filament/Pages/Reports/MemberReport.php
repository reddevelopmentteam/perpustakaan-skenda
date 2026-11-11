<?php

namespace App\Filament\Pages\Reports;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\Students\Schemas\StudentForm;
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
 * Laporan anggota: tabel students with(user, loans, fines),
 * filter kelas + status, ringkasan per siswa dihitung agregat SQL.
 */
class MemberReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Laporan Anggota';

    protected static ?string $title = 'Laporan Anggota';

    protected static ?string $slug = 'laporan-anggota';

    /** @var list<string> */
    private static function activeLoanStatuses(): array
    {
        return collect(LoanStatus::cases())
            ->filter(fn (LoanStatus $status): bool => $status->isActive())
            ->map(fn (LoanStatus $status): string => $status->value)
            ->all();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('viewAny', Student::class) && ($user->isStaff() || $user->isSuperAdmin());
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
        $active = self::activeLoanStatuses();

        return $table
            ->query(fn (): Builder => Student::query()
                ->with(['user'])
                ->withCount([
                    'loans as loans_count',
                    'loans as active_loans_count' => fn (Builder $q): Builder => $q->whereIn('status', $active),
                    'loans as overdue_loans_count' => fn (Builder $q): Builder => $q->where('status', LoanStatus::OVERDUE->value),
                    'reservations as active_reservations_count' => fn (Builder $q): Builder => $q->whereIn('status', [
                        ReservationStatus::WAITING->value,
                        ReservationStatus::AVAILABLE->value,
                    ]),
                ])
                ->withSum(['fines as unpaid_fines_sum' => fn (Builder $q): Builder => $q->where('status', FineStatus::UNPAID->value)], 'amount'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('student_number')
                    ->label('NIS')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('class')
                    ->label('Kelas')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof StudentStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        StudentStatus::ACTIVE => 'success',
                        StudentStatus::BLOCKED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('active_loans_count')
                    ->label('Aktif')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('overdue_loans_count')
                    ->label('Terlambat')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => ((int) $state) > 0 ? 'danger' : 'gray'),
                TextColumn::make('unpaid_fines_sum')
                    ->label('Denda')
                    ->money('IDR')
                    ->sortable()
                    ->placeholder('Rp0'),
                TextColumn::make('active_reservations_count')
                    ->label('Reservasi')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('loans_count')
                    ->label('Total Pinjam')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('class')
                    ->label('Kelas')
                    ->options(StudentForm::classOptions()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(StudentStatus::cases())->mapWithKeys(fn (StudentStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ])
            ->emptyStateIcon('heroicon-m-users')
            ->emptyStateHeading('Belum ada anggota')
            ->emptyStateDescription('Daftarkan anggota baru melalui menu Master Data > Anggota.');
    }

    public function exportCsv(): StreamedResponse
    {
        $active = self::activeLoanStatuses();

        $rows = $this->getTableQueryForExport()
            ->withCount([
                'loans as loans_count',
                'loans as active_loans_count' => fn (Builder $q): Builder => $q->whereIn('status', $active),
                'loans as overdue_loans_count' => fn (Builder $q): Builder => $q->where('status', LoanStatus::OVERDUE->value),
            ])
            ->withSum(['fines as unpaid_fines_sum' => fn (Builder $q): Builder => $q->where('status', FineStatus::UNPAID->value)], 'amount')
            ->orderBy('name')
            ->cursor()
            ->map(fn (Student $student): array => [
                $student->student_number,
                $student->name,
                $student->class,
                $student->status instanceof StudentStatus ? $student->status->label() : (string) $student->status,
                (string) ((int) ($student->active_loans_count ?? 0)),
                (string) ((int) ($student->overdue_loans_count ?? 0)),
                (string) ((int) ($student->unpaid_fines_sum ?? 0)),
                (string) ((int) ($student->loans_count ?? 0)),
            ])
            ->all();

        $filename = 'laporan-anggota-'.now()->format('Ymd-His').'.csv';

        return ReportCsvExporter::export(
            $filename,
            ['NIS', 'Nama', 'Kelas', 'Status', 'Aktif', 'Terlambat', 'Denda', 'Total Pinjam'],
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
