<?php

namespace App\Filament\Resources\BookCopies;

use App\Enums\BookCopyStatus;
use App\Enums\LoanItemStatus;
use App\Filament\Resources\BookCopies\Pages\CreateBookCopy;
use App\Filament\Resources\BookCopies\Pages\EditBookCopy;
use App\Filament\Resources\BookCopies\Pages\ListBookCopies;
use App\Filament\Resources\BookCopies\Pages\ViewBookCopy;
use App\Models\Book;
use App\Models\BookCopy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BookCopyResource extends Resource
{
    protected static ?string $model = BookCopy::class;

    protected static ?string $modelLabel = 'Eksemplar Buku';

    protected static ?string $pluralModelLabel = 'Eksemplar Buku';

    protected static ?string $recordTitleAttribute = 'inventory_code';

    protected static ?string $navigationLabel = 'Eksemplar Buku';

    protected static string|\UnitEnum|null $navigationGroup = 'Perpustakaan';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;
    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (BookCopyStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('book_id')
                ->label('Buku')
                ->relationship('book', 'title')
                ->getOptionLabelFromRecordUsing(fn (Book $record): string => trim($record->title.' — '.$record->author, ' —'))
                ->searchable()
                ->preload()
                ->required()
                ->validationMessages([
                    'required' => 'Buku wajib dipilih.',
                ]),
            TextInput::make('inventory_code')
                ->label('Nomor Induk')
                ->required()
                ->maxLength(255)
                ->regex('/^BK-\d{6}$/')
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'required' => 'Nomor induk wajib diisi.',
                    'regex' => 'Format nomor induk harus BK- diikuti 6 digit (contoh BK-000001).',
                    'unique' => 'Nomor induk sudah digunakan.',
                ])
                ->suffixAction(
                    Action::make('generate')
                        ->label('Generate')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (Set $set): void {
                            do {
                                $code = 'BK-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            } while (BookCopy::where('inventory_code', $code)->exists());

                            $set('inventory_code', $code);
                        })
                ),
            Select::make('status')
                ->label('Status')
                ->options(self::statusOptions())
                ->default(BookCopyStatus::AVAILABLE)
                ->required()
                ->native(false)
                ->validationMessages([
                    'required' => 'Status wajib dipilih.',
                ]),
            Select::make('condition')
                ->label('Kondisi Fisik')
                ->options([
                    'Baik' => 'Baik',
                    'Sampul kusam' => 'Sampul kusam',
                    'Halaman lepas' => 'Halaman lepas',
                    'Perlu jilid ulang' => 'Perlu jilid ulang',
                ])
                ->placeholder('Pilih kondisi fisik')
                ->native(false),
            TextInput::make('shelf_location')
                ->label('Lokasi Rak')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Catatan')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inventory_code')
                    ->label('Nomor Induk')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('book.title')
                    ->label('Judul Buku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state instanceof BookCopyStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof BookCopyStatus ? $state->value : $state) {
                        'available' => 'success',
                        'borrowed' => 'info',
                        'reserved' => 'warning',
                        'lost', 'damaged' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('condition')
                    ->label('Kondisi Fisik')
                    ->toggleable(),
                TextColumn::make('book.shelf_location')
                    ->label('Lokasi Rak')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                SelectFilter::make('book_id')
                    ->label('Buku')
                    ->relationship('book', 'title')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (BookCopy $record): bool => $record->status !== BookCopyStatus::BORROWED),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Eksemplar')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('inventory_code')
                            ->label('Nomor Induk')
                            ->copyable()
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof BookCopyStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => match ($state instanceof BookCopyStatus ? $state->value : $state) {
                                'available' => 'success',
                                'borrowed' => 'info',
                                'reserved' => 'warning',
                                'lost', 'damaged' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('condition')
                            ->label('Kondisi Fisik'),
                        TextEntry::make('shelf_location')
                            ->label('Lokasi Rak'),
                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->columnSpanFull(),
                    ]),
                ]),
            Section::make('Buku')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('book.title')
                            ->label('Judul'),
                        TextEntry::make('book.author')
                            ->label('Penulis'),
                        TextEntry::make('book.category.name')
                            ->label('Kategori'),
                        TextEntry::make('book.publisher')
                            ->label('Penerbit'),
                    ]),
                ]),
            Section::make('Riwayat Peminjaman')
                ->schema([
                    RepeatableEntry::make('loanItems')
                        ->label('10 Peminjaman Terakhir')
                        ->state(fn (BookCopy $record) => $record->loanItems()->with(['loan.student'])->latest()->limit(10)->get())
                        ->schema([
                            TextEntry::make('loan.loan_number')
                                ->label('Nomor Pinjaman'),
                            TextEntry::make('loan.student.name')
                                ->label('Siswa'),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => $state instanceof LoanItemStatus ? $state->label() : (string) $state),
                            TextEntry::make('created_at')
                                ->label('Tanggal')
                                ->dateTime('d M Y'),
                        ]),
                ]),
        ]);
    }

    /**
     * @return Builder<BookCopy>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['book']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookCopies::route('/'),
            'create' => CreateBookCopy::route('/create'),
            'view' => ViewBookCopy::route('/{record}'),
            'edit' => EditBookCopy::route('/{record}/edit'),
        ];
    }
}
