<?php

namespace App\Filament\Resources\LoanExtensions\Tables;

use App\Enums\ExtensionStatus;
use App\Exceptions\LoanException;
use App\Models\LoanExtension;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class LoanExtensionsTable
{
    public static function extensionStatusColor(ExtensionStatus|string $state): string
    {
        $status = $state instanceof ExtensionStatus ? $state : ExtensionStatus::tryFrom((string) $state);

        return match ($status) {
            ExtensionStatus::PENDING => 'warning',
            ExtensionStatus::APPROVED => 'success',
            ExtensionStatus::REJECTED => 'danger',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('loan.loan_number')
                    ->label('Nomor Pinjaman')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('medium'),
                TextColumn::make('loan.student.name')
                    ->label('Siswa')
                    ->searchable()
                    ->description(fn (LoanExtension $record): ?string => $record->loan?->student?->class),
                TextColumn::make('old_due_at')
                    ->label('Tenggat Lama')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('new_due_at')
                    ->label('Tenggat Baru')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ExtensionStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => self::extensionStatusColor($state)),
                TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ExtensionStatus::cases())->mapWithKeys(fn (ExtensionStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->requiresConfirmation(),
                ]),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approveExtension')
            ->label('Setujui')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Setujui perpanjangan')
            ->modalDescription('Tenggat peminjaman akan diperpanjang sesuai aturan (durasi & batas dari pengaturan).')
            ->visible(fn (LoanExtension $record): bool => $record->status === ExtensionStatus::PENDING
                && Gate::allows('approve', $record))
            ->authorize(fn (LoanExtension $record): bool => Gate::allows('approve', $record))
            ->action(function (LoanExtension $record): void {
                try {
                    app(\App\Services\LoanExtensionService::class)->approve($record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Perpanjangan disetujui.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menyetujui perpanjangan.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('rejectExtension')
            ->label('Tolak')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Tolak perpanjangan')
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
            ->visible(fn (LoanExtension $record): bool => $record->status === ExtensionStatus::PENDING
                && Gate::allows('reject', $record))
            ->authorize(fn (LoanExtension $record): bool => Gate::allows('reject', $record))
            ->action(function (LoanExtension $record, array $data): void {
                try {
                    app(\App\Services\LoanExtensionService::class)->reject(
                        $record,
                        auth()->user(),
                        (string) ($data['reason'] ?? ''),
                    );

                    Notification::make()
                        ->success()
                        ->title('Perpanjangan ditolak.')
                        ->send();
                } catch (LoanException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Gagal menolak perpanjangan.')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
