<?php

namespace App\Filament\Resources\Books\RelationManagers;

use App\Enums\BookCopyStatus;
use App\Models\BookCopy;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CopiesRelationManager extends RelationManager
{
    protected static string $relationship = 'copies';

    protected static ?string $title = 'Eksemplar';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('barcode')
                    ->label('Barcode')
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->regex('/^BK-\d{6}$/')
                    ->helperText('Format BK-000001; kosongkan untuk generate otomatis.')
                    ->validationMessages([
                        'unique' => 'Barcode sudah digunakan.',
                        'regex' => 'Format barcode harus BK- diikuti 6 digit (contoh BK-000001).',
                    ]),
                TextInput::make('inventory_code')
                    ->label('Kode Inventaris')
                    ->nullable()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'Kode inventaris sudah digunakan.',
                    ]),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(BookCopyStatus::cases())->mapWithKeys(fn (BookCopyStatus $status): array => [
                        $status->value => $status->label(),
                    ])->all())
                    ->default(BookCopyStatus::AVAILABLE->value)
                    ->required()
                    ->validationMessages([
                        'required' => 'Status wajib dipilih.',
                    ]),
                TextInput::make('condition')
                    ->label('Kondisi')
                    ->nullable()
                    ->maxLength(255),
                TextInput::make('shelf_location')
                    ->label('Lokasi Rak')
                    ->nullable()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->nullable()
                    ->columnSpanFull()
                    ->rows(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('barcode')
            ->columns([
                TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('inventory_code')
                    ->label('Kode Inventaris')
                    ->toggleable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (BookCopyStatus $state): string => match ($state) {
                        BookCopyStatus::AVAILABLE => 'success',
                        BookCopyStatus::BORROWED => 'info',
                        BookCopyStatus::RESERVED => 'warning',
                        BookCopyStatus::LOST, BookCopyStatus::DAMAGED => 'danger',
                        BookCopyStatus::MAINTENANCE => 'gray',
                    })
                    ->formatStateUsing(fn (BookCopyStatus $state): string => $state->label()),
                TextColumn::make('condition')
                    ->label('Kondisi')
                    ->placeholder('-'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Eksemplar')
                    ->mutateFormDataUsing(function (array $data): array {
                        if (blank($data['barcode'] ?? null)) {
                            $data['barcode'] = self::generateBarcode();
                        }

                        return $data;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function generateBarcode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $barcode = 'BK-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            if (! BookCopy::query()->where('barcode', $barcode)->exists()) {
                return $barcode;
            }
        }

        $last = BookCopy::query()->orderByDesc('id')->value('barcode');

        $next = 1;

        if (is_string($last) && preg_match('/^BK-(\d{6})$/', $last, $matches)) {
            $next = ((int) $matches[1] + 1) % 1000000;
        }

        return 'BK-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
