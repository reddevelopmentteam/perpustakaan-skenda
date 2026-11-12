<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Enums\LoanStatus;
use App\Exceptions\LoanException;
use App\Models\BookCopy;
use App\Models\Loan;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class LoansTable
{
    public static function statusColor(LoanStatus|string $state): string
    {
        $status = $state instanceof LoanStatus ? $state : LoanStatus::tryFrom((string) $state);

        return match ($status) {
            LoanStatus::PENDING => 'warning',
            LoanStatus::APPROVED => 'success',
            LoanStatus::BORROWED => 'info',
            LoanStatus::PARTIALLY_RETURNED => 'info',
            LoanStatus::RETURNED => 'success',
            LoanStatus::REJECTED => 'danger',
            LoanStatus::OVERDUE => 'danger',
            LoanStatus::CANCELLED => 'gray',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('loan_number')
                    ->label('Nomor Pinjaman')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Nomor pinjaman disalin.')
                    ->weight('medium'),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Loan $record): ?string => $record->student?->class),
                TextColumn::make('items_count')
                    ->label('Buku')
                    ->numeric()
                    ->sortable()
                    ->counts('items'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LoanStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => self::statusColor($state)),
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
                    ->placeholder('-')
                    ->color(fn (Loan $record): ?string => $record->due_at !== null
                        && $record->due_at->isPast()
                        && $record->isActive()
                        ? 'danger'
                        : null)
                    ->weight(fn (Loan $record): ?string => $record->due_at !== null
                        && $record->due_at->isPast()
                        && $record->isActive()
                        ? 'bold'
                        : null),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(LoanStatus::cases())->mapWithKeys(fn (LoanStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('borrowed_at')
                    ->label('Tanggal Pinjam')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('borrowed_from')
                            ->label('Dari tanggal'),
                        \Filament\Forms\Components\DatePicker::make('borrowed_until')
                            ->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['borrowed_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('borrowed_at', '>=', $date))
                        ->when($data['borrowed_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('borrowed_at', '<=', $date)))
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
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
                self::markBorrowedAction(),
                self::cancelAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->requiresConfirmation(),
                ]),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Setujui peminjaman')
            ->modalDescription('Peminjaman akan disetujui. Stok keluar saat buku diserahkan (Tandai Dipinjam).')
            ->visible(fn (Loan $record): bool => $record->status === LoanStatus::PENDING
                && Gate::allows('approve', $record))
            ->authorize(fn (Loan $record): bool => Gate::allows('approve', $record))
            ->action(function (Loan $record): void {
                try {
                    app(\App\Services\LoanService::class)->approve($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Peminjaman disetujui.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menyetujui peminjaman.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Tolak peminjaman')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan Penolakan')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000)
                    ->validationMessages([
                        'required' => 'Alasan penolakan wajib diisi.',
                    ]),
            ])
            ->visible(fn (Loan $record): bool => $record->status === LoanStatus::PENDING
                && Gate::allows('reject', $record))
            ->authorize(fn (Loan $record): bool => Gate::allows('reject', $record))
            ->action(function (Loan $record, array $data): void {
                try {
                    app(\App\Services\LoanService::class)->reject($record, auth()->user(), (string) ($data['reason'] ?? ''));

                    Notification::make()
                        ->success()
                        ->title('Peminjaman ditolak.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menolak peminjaman.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function markBorrowedAction(): Action
    {
        return Action::make('markBorrowed')
            ->label('Tandai Dipinjam')
            ->icon('heroicon-m-book-open')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Serahkan buku (tandai dipinjam)')
            ->modalDescription('Pilih eksemplar fisik (nomor induk) untuk setiap buku. Stok keluar saat aksi ini dijalankan.')
            ->schema(fn (Loan $record): array => [
                Repeater::make('copies')
                    ->label('Eksemplar')
                    ->minItems($record->items()->count())
                    ->maxItems($record->items()->count())
                    ->deletable(false)
                    ->addable(false)
                    ->reorderable(false)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('loan_item_id')
                            ->label('Item')
                            ->options(fn (): array => $record->items()->with('book')->get()
                                ->mapWithKeys(fn ($item): array => [
                                    $item->id => ($item->book?->title ?? 'Buku #'.$item->book_id),
                                ])
                                ->all())
                            ->required()
                            ->validationMessages([
                                'required' => 'Item wajib dipilih.',
                            ]),
                        Select::make('book_copy_id')
                            ->label('Eksemplar (nomor induk)')
                            ->options(function (Get $get): array {
                                $itemId = $get('loan_item_id');

                                if (! $itemId) {
                                    return [];
                                }

                                $item = $record->items()->find($itemId);

                                if (! $item) {
                                    return [];
                                }

                                return BookCopy::query()
                                    ->where('book_id', $item->book_id)
                                    ->where('status', \App\Enums\BookCopyStatus::AVAILABLE)
                                    ->orderBy('inventory_code')
                                    ->limit(100)
                                    ->get()
                                    ->mapWithKeys(fn (BookCopy $copy): array => [
                                        $copy->id => $copy->inventory_code,
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->required()
                            ->validationMessages([
                                'required' => 'Eksemplar wajib dipilih.',
                            ]),
                    ])
                    ->columns(2),
            ])
            ->visible(fn (Loan $record): bool => $record->status === LoanStatus::APPROVED
                && Gate::allows('markBorrowed', $record))
            ->authorize(fn (Loan $record): bool => Gate::allows('markBorrowed', $record))
            ->action(function (Loan $record, array $data): void {
                try {
                    $copyMap = [];

                    foreach ((array) ($data['copies'] ?? []) as $row) {
                        if (isset($row['loan_item_id'], $row['book_copy_id'])) {
                            $copyMap[(int) $row['loan_item_id']] = (int) $row['book_copy_id'];
                        }
                    }

                    app(\App\Services\LoanService::class)->markBorrowed($record, auth()->user(), $copyMap);

                    Notification::make()
                        ->success()
                        ->title('Buku diserahkan, status menjadi Dipinjam.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menandai dipinjam.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Batalkan')
            ->icon('heroicon-m-no-symbol')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Batalkan peminjaman')
            ->modalDescription('Peminjaman yang dibatalkan tidak dapat dikembalikan ke status sebelumnya.')
            ->visible(fn (Loan $record): bool => in_array($record->status, [LoanStatus::PENDING, LoanStatus::APPROVED], true))
            ->action(function (Loan $record): void {
                try {
                    app(\App\Services\LoanService::class)->cancel($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Peminjaman dibatalkan.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal membatalkan peminjaman.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
