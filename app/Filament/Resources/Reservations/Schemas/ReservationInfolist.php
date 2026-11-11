<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Reservasi')
                    ->icon('heroicon-m-bookmark')
                    ->compact()
                    ->schema([
                        TextEntry::make('reservation_number')
                            ->label('Nomor Reservasi')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof ReservationStatus ? $state->label() : (string) $state)
                            ->color(fn ($state): string => ReservationsTable::statusColor($state)),
                        TextEntry::make('queue_ahead')
                            ->label('Antrean di Depan')
                            ->state(fn (Reservation $record): int => self::queueAhead($record)),
                        TextEntry::make('student.name')
                            ->label('Siswa'),
                        TextEntry::make('student.student_number')
                            ->label('NIS')
                            ->placeholder('-'),
                        TextEntry::make('student.class')
                            ->label('Kelas')
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('book.title')
                            ->label('Judul Buku'),
                        TextEntry::make('book.author')
                            ->label('Penulis')
                            ->placeholder('-'),
                        TextEntry::make('book.category.name')
                            ->label('Kategori')
                            ->badge()
                            ->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Linimasa')
                    ->icon('heroicon-m-clock')
                    ->compact()
                    ->schema([
                        TextEntry::make('reserved_at')
                            ->label('Direservasi')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('expires_at')
                            ->label('Batas Ambil')
                            ->dateTime('d M Y')
                            ->placeholder('-')
                            ->color(fn (Reservation $record): ?string => $record->status === ReservationStatus::AVAILABLE ? 'success' : null),
                        TextEntry::make('fulfilled_at')
                            ->label('Diambil')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('cancelled_at')
                            ->label('Dibatalkan')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('queue_position')
                            ->label('Posisi Antrean')
                            ->placeholder('-'),
                        TextEntry::make('hold_note')
                            ->label('Catatan Ambil')
                            ->state(fn (Reservation $record): ?string => $record->status === ReservationStatus::AVAILABLE && $record->expires_at !== null
                                ? 'Ambil sebelum '.$record->expires_at->format('d M Y').'.'
                                : null)
                            ->placeholder('-')
                            ->visible(fn (Reservation $record): bool => $record->status === ReservationStatus::AVAILABLE)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    private static function queueAhead(Reservation $record): int
    {
        if ($record->status !== ReservationStatus::WAITING) {
            return 0;
        }

        return Reservation::query()
            ->where('book_id', $record->book_id)
            ->where('status', ReservationStatus::WAITING->value)
            ->where('queue_position', '<', $record->queue_position)
            ->count();
    }
}
