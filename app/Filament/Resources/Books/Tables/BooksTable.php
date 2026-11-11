<?php

namespace App\Filament\Resources\Books\Tables;

use App\Models\Book;
use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class BooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover')
                    ->label('Cover')
                    ->disk('public')
                    ->imageSize(100)
                    ->defaultImageUrl(null)
                    ->extraImgAttributes(['class' => 'object-contain'])
                    ->grow(false),
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('author')
                    ->label('Penulis')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('isbn')
                    ->label('ISBN')
                    ->searchable()
                    ->copyable()
                    ->toggleable()
                    ->placeholder('-'),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Tanpa kategori')
                    ->sortable(),
                TextColumn::make('publication_year')
                    ->label('Tahun')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_stock')
                    ->label('Ketersediaan')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.' tersedia' : 'Habis')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('available_stock')
                    ->label('Ketersediaan')
                    ->trueLabel('Tersedia')
                    ->falseLabel('Habis')
                    ->queries(
                        true: fn ($query) => $query->where('available_stock', '>', 0),
                        false: fn ($query) => $query->where('available_stock', '<=', 0),
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
                RestoreAction::make(),
                ForceDeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->striped()
            ->defaultPaginationPageOption(10);
    }
}
