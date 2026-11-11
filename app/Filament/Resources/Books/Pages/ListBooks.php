<?php

namespace App\Filament\Resources\Books\Pages;

use App\Filament\Resources\Books\BookResource;
use App\Models\Book;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListBooks extends ListRecords
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('import')
                ->label('Impor Buku')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->modal()
                ->form([
                    FileUpload::make('csv_file')
                        ->label('File CSV')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                        ->required()
                        ->helperText('Header: title, author, isbn, publisher, publication_year, category, stock, shelf_location, description'),
                ])
                ->action(function (array $data): void {
                    $file = $data['csv_file'];
                    $path = $file->getRealPath();

                    if (($handle = fopen($path, 'r')) === false) {
                        Notification::make()
                            ->danger()
                            ->title('Gagal membuka file CSV.')
                            ->send();

                        return;
                    }

                    $header = fgetcsv($handle);
                    if ($header === false) {
                        fclose($handle);
                        Notification::make()
                            ->danger()
                            ->title('File CSV kosong atau tidak valid.')
                            ->send();

                        return;
                    }

                    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
                    $rows = [];

                    while (($row = fgetcsv($handle)) !== false) {
                        $rows[] = array_combine($header, array_map(fn ($v) => trim((string) $v), $row));
                    }

                    fclose($handle);

                    $imported = 0;
                    $invalid = 0;
                    $line = 2;

                    foreach ($rows as $row) {
                        $errors = [];
                        $title = $row['title'] ?? '';
                        $author = $row['author'] ?? '';
                        $stock = (int) ($row['stock'] ?? 0);

                        if ($title === '') {
                            $errors[] = 'Judul wajib diisi.';
                        }

                        if ($author === '') {
                            $errors[] = 'Penulis wajib diisi.';
                        }

                        if ($stock < 0) {
                            $errors[] = 'Stok tidak boleh negatif.';
                        }

                        $categoryId = null;
                        $categoryName = $row['category'] ?? $row['kategori'] ?? '';
                        if ($categoryName !== '') {
                            $category = Category::query()->where('name', $categoryName)->first();
                            if ($category === null) {
                                $errors[] = "Kategori \"{$categoryName}\" tidak ditemukan.";
                            } else {
                                $categoryId = $category->id;
                            }
                        }

                        $isbn = $row['isbn'] ?? null;
                        if ($isbn !== null && $isbn !== '' && Book::query()->where('isbn', $isbn)->exists()) {
                            $errors[] = "ISBN \"{$isbn}\" sudah terdaftar.";
                        }

                        if ($errors !== []) {
                            $invalid++;
                            $line++;
                            continue;
                        }

                        try {
                            Book::create([
                                'isbn' => $isbn ?? null,
                                'title' => $title,
                                'author' => $author,
                                'publisher' => $row['publisher'] ?? $row['penerbit'] ?? null,
                                'publication_year' => $row['publication_year'] ?? $row['tahun_terbit'] ?? null,
                                'category_id' => $categoryId,
                                'stock' => $stock,
                                'available_stock' => $stock,
                                'shelf_location' => $row['shelf_location'] ?? $row['lokasi_rak'] ?? null,
                                'description' => $row['description'] ?? $row['deskripsi'] ?? null,
                            ]);

                            $imported++;
                        } catch (\Throwable $e) {
                            $invalid++;
                        }

                        $line++;
                    }

                    Notification::make()
                        ->title("Impor selesai: {$imported} berhasil, {$invalid} gagal")
                        ->success()
                        ->send();
                }),
        ];
    }
}
