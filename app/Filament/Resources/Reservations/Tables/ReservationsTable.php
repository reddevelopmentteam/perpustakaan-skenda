<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Exceptions\LoanException;
use App\Models\Reservation;
use App\Services\ReservationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ReservationsTable
{
    public static function statusColor(ReservationStatus|string $state): string
    {
        $status = $state instanceof ReservationStatus ? $state : ReservationStatus::tryFrom((string) $state);

        return match ($status) {
            ReservationStatus::WAITING => 'warning',
            ReservationStatus::AVAILABLE => 'success',
            ReservationStatus::FULFILLED => 'success',
            ReservationStatus::EXPIRED => 'gray',
            ReservationStatus::CANCELLED => 'gray',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reservation_number')
                    ->label('Nomor Reservasi')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Nomor reservasi disalin.')
                    ->weight('medium'),
                TextColumn::make('student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Reservation $record): ?string => $record->student?->class),
                TextColumn::make('book.title')
                    ->label('Buku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('queue_position')
                    ->label('Antrean')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ReservationStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('reserved_at')
                    ->label('Direservasi')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Batas Ambil')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ReservationStatus::cases())->mapWithKeys(fn (ReservationStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('book')
                    ->label('Buku')
                    ->relationship('book', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                self::cancelAction(),
                self::fulfillAction(),
            ]);
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Batalkan')
            ->icon('heroicon-m-no-symbol')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Batalkan reservasi')
            ->modalDescription('Reservasi yang dibatalkan tidak dapat dikembalikan ke status sebelumnya.')
            ->visible(fn (Reservation $record): bool => $record->isActive()
                && Gate::allows('cancel', $record))
            ->authorize(fn (Reservation $record): bool => Gate::allows('cancel', $record))
            ->action(function (Reservation $record): void {
                try {
                    ReservationService::cancel($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Reservasi dibatalkan.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal membatalkan reservasi.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function fulfillAction(): Action
    {
        return Action::make('fulfill')
            ->label('Tandai Diambil')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Selesaikan reservasi')
            ->modalDescription('Gunakan setelah buku diserahkan via peminjaman.')
            ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::AVAILABLE
                && self::isStaffLike())
            ->authorize(fn (): bool => self::isStaffLike())
            ->action(function (Reservation $record): void {
                try {
                    ReservationService::fulfill($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Reservasi diselesaikan.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menyelesaikan reservasi.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    private static function isStaffLike(): bool
    {
        $user = auth()->user();

        return (bool) $user && ($user->isStaff() || $user->isSuperAdmin());
    }
}
