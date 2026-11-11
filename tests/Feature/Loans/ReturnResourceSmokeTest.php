<?php

namespace Tests\Feature\Loans;

use App\Enums\FineStatus;
use App\Filament\Pages\ReturnBooks;
use App\Filament\Resources\Fines\FineResource;
use App\Filament\Resources\Fines\Pages\ListFines;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_petugas_dapat_membuka_denda_dan_pengembalian(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $this->get(FineResource::getUrl('index'))->assertOk();
        $this->get(ReturnBooks::getUrl())->assertOk();
    }

    public function test_siswa_tidak_bisa_akses_halaman_pengembalian(): void
    {
        $student = Student::factory()->create();
        $studentUser = $student->user;

        $this->assertNotNull($studentUser);

        $this->actingAs($studentUser);

        $response = $this->get(ReturnBooks::getUrl());

        $this->assertTrue(in_array($response->status(), [302, 403], true));

        $this->assertFalse(ReturnBooks::canAccess());
    }

    public function test_aksi_bayar_tampil_untuk_denda_belum_dibayar(): void
    {
        $staff = User::factory()->staff()->create();
        $student = Student::factory()->create();

        Book::factory()->create(['stock' => 1, 'available_stock' => 0]);
        $book = Book::query()->first();
        $copy = BookCopy::factory()->create(['book_id' => $book->id]);

        $loan = Loan::create([
            'loan_number' => 'PJ-20260101-0007',
            'student_id' => $student->id,
            'status' => \App\Enums\LoanStatus::BORROWED,
            'borrowed_at' => now()->subDays(10),
            'due_at' => now()->subDays(3),
        ]);

        $fine = Fine::create([
            'loan_id' => $loan->id,
            'student_id' => $student->id,
            'amount' => 3000,
            'late_days' => 3,
            'fine_rate_snapshot' => 1000,
            'status' => FineStatus::UNPAID,
        ]);

        $this->actingAs($staff);

        Livewire::test(ListFines::class)
            ->assertTableActionVisible('pay', $fine);

        $fine->status = FineStatus::PAID;
        $fine->paid_at = now();
        $fine->paid_by = $staff->id;
        $fine->save();

        Livewire::test(ListFines::class)
            ->assertTableActionHidden('pay', $fine->fresh());
    }
}
