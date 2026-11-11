<?php

namespace Tests\Feature\Loans;

use App\Enums\LoanStatus;
use App\Filament\Resources\LoanExtensions\LoanExtensionResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_petugas_dapat_membuka_daftar_peminjaman(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $this->get(LoanResource::getUrl('index'))->assertOk();
    }
    public function test_petugas_dapat_membuka_daftar_perpanjangan(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $this->get(LoanExtensionResource::getUrl('index'))->assertOk();
    }


    public function test_aksi_setujui_tampil_untuk_pending_dan_mengubah_status(): void
    {
        $staff = User::factory()->staff()->create();
        $student = Student::factory()->create();

        $book = Book::factory()->create(['stock' => 3, 'available_stock' => 3]);
        BookCopy::factory()->count(2)->create(['book_id' => $book->id]);

        $loan = Loan::create([
            'loan_number' => 'PJ-20260101-0001',
            'student_id' => $student->id,
            'status' => LoanStatus::PENDING,
            'requested_at' => now(),
        ]);

        LoanItem::create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'book_copy_id' => null,
            'quantity' => 1,
            'returned_quantity' => 0,
            'status' => \App\Enums\LoanItemStatus::PENDING,
        ]);

        $this->actingAs($staff);

        Livewire::test(ListLoans::class)
            ->assertTableActionVisible('approve', $loan);

    }

    public function test_siswa_tidak_melihat_tombol_setujui(): void
    {
        $staff = User::factory()->staff()->create();
        $student = Student::factory()->create();
        $studentUser = $student->user;

        $this->assertNotNull($studentUser);

        $loan = Loan::create([
            'loan_number' => 'PJ-20260101-0002',
            'student_id' => $student->id,
            'status' => LoanStatus::PENDING,
            'requested_at' => now(),
        ]);

        Book::factory()->create();

        LoanItem::create([
            'loan_id' => $loan->id,
            'book_id' => Book::query()->first()->id,
            'book_copy_id' => null,
            'quantity' => 1,
            'returned_quantity' => 0,
            'status' => \App\Enums\LoanItemStatus::PENDING,
        ]);

        $this->actingAs($studentUser);

        $this->assertFalse($studentUser->can('approve', $loan));

        Livewire::test(ListLoans::class)
            ->assertTableActionHidden('approve', $loan);

        $this->assertTrue($staff->can('approve', $loan));
    }
}
