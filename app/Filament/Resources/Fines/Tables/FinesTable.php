<?php

namespace App\Filament\Resources\Fines\Tables;

use App\Enums\FineStatus;
use App\Exceptions\LoanException;
use App\Models\Fine;
use App\Services\FineService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class FinesTable
{
    public static function statusColor(FineStatus|string $state): string
    {
        $status = $state instanceof FineStatus ? $state : FineStatus::tryFrom((string) $state);

        return match ($status) {
            FineStatus::UNPAID => 'danger',
            FineStatus::PAID => 'success',
            FineStatus::WAIVED => 'gray',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('loan.loan_number')
                    ->label('No. Pinjaman')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Nomor pinjaman disalin.')
                    ->weight('medium'),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Fine $record): ?string => $record->student?->class),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('late_days')
                    ->label('Hari Terlambat')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof FineStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('paid_at')
                    ->label('Dibayar')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(FineStatus::cases())->mapWithKeys(fn (FineStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('paid_at')
                    ->label('Tanggal Bayar')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('paid_from')
                            ->label('Dari tanggal'),
                        \Filament\Forms\Components\DatePicker::make('paid_until')
                            ->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['paid_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['paid_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('paid_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['paid_from'] ?? null) {
                            $indicators[] = 'Dari '.$data['paid_from'];
                        }

                        if ($data['paid_until'] ?? null) {
                            $indicators[] = 'Sampai '.$data['paid_until'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                self::payAction(),
                self::waiveAction(),
            ]);
    }

    public static function payAction(): Action
    {
        return Action::make('pay')
            ->label('Bayar')
            ->icon('heroicon-m-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Bayar denda')
            ->modalDescription(fn (Fine $record): string => 'Tandai denda sebesar Rp '.number_format((int) $record->amount, 0, ',', '.').' ('.$record->late_days.' hari terlambat) sebagai lunas?')
            ->schema([
                Textarea::make('notes')
                    ->label('Catatan (opsional)')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->visible(fn (Fine $record): bool => $record->status === FineStatus::UNPAID
                && Gate::allows('update', $record))
            ->authorize(fn (Fine $record): bool => Gate::allows('update', $record))
            ->action(function (Fine $record, array $data): void {
                try {
                    \App\Services\FineService::pay($record, auth()->user(), $data['notes'] ?? null);

                    Notification::make()
                        ->success()
                        ->title('Denda berhasil dibayar.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal membayar denda.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function waiveAction(): Action
    {
        return Action::make('waive')
            ->label('Bebaskan')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Bebaskan denda')
            ->modalDescription(fn (Fine $record): string => 'Bebaskan denda sebesar Rp '.number_format((int) $record->amount, 0, ',', '.').' untuk pinjaman '.$record->loan?->loan_number.'? Denda yang dibebaskan tidak dapat dikembalikan menjadi belum dibayar.')
            ->schema([
                Textarea::make('notes')
                    ->label('Alasan pembebasan')
                    ->required()
                    ->rows(2)
                    ->maxLength(2000)
                    ->validationMessages([
                        'required' => 'Alasan pembebasan wajib diisi.',
                    ]),
            ])
            ->visible(fn (Fine $record): bool => $record->status === FineStatus::UNPAID
                && Gate::allows('waive', $record))
            ->authorize(fn (Fine $record): bool => Gate::allows('waive', $record))
            ->action(function (Fine $record, array $data): void {
                try {
                    \App\Services\FineService::waive($record, auth()->user(), (string) ($data['notes'] ?? ''));

                    Notification::make()
                        ->success()
                        ->title('Denda dibebaskan.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal membebaskan denda.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
