<?php

namespace Tests\Feature\Loans;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Book;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_petugas_dapat_membuka_daftar_reservasi(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $this->get(ReservationResource::getUrl('index'))->assertOk();
    }

    public function test_aksi_batalkan_tampil_untuk_menunggu(): void
    {
        $staff = User::factory()->staff()->create();
        $student = Student::factory()->create();
        $book = Book::factory()->create(['stock' => 0, 'available_stock' => 0]);

        $reservation = Reservation::create([
            'reservation_number' => 'RSV-20260101-0001',
            'student_id' => $student->id,
            'book_id' => $book->id,
            'status' => ReservationStatus::WAITING,
            'reserved_at' => now(),
            'queue_position' => 1,
        ]);

        $this->actingAs($staff);

        Livewire::test(ListReservations::class)
            ->assertTableActionVisible('cancel', $reservation);
    }

    public function test_siswa_tidak_melihat_tombol_selesai(): void
    {
        $student = Student::factory()->create();
        $studentUser = $student->user;

        $this->assertNotNull($studentUser);

        $book = Book::factory()->create(['stock' => 0, 'available_stock' => 0]);

        $reservation = Reservation::create([
            'reservation_number' => 'RSV-20260101-0002',
            'student_id' => $student->id,
            'book_id' => $book->id,
            'status' => ReservationStatus::AVAILABLE,
            'reserved_at' => now()->subDays(2),
            'expires_at' => now()->addDay(),
            'queue_position' => 1,
        ]);

        $this->actingAs($studentUser);

        Livewire::test(ListReservations::class)
            ->assertTableActionHidden('fulfill', $reservation);
    }
}
