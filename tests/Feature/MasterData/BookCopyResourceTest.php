<?php

namespace Tests\Feature\MasterData;

use App\Enums\BookCopyStatus;
use App\Filament\Resources\BookCopies\BookCopyResource;
use App\Filament\Resources\BookCopies\Pages\ListBookCopies;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BookCopyResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $studentUser;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $student = Student::factory()->create();
        $this->studentUser = $student->user;
        $this->book = Book::factory()->create();
    }

    public function test_petugas_bisa_membuka_daftar_eksemplar(): void
    {
        $this->actingAs($this->staff);

        $this->get(BookCopyResource::getUrl('index'))->assertOk();
    }

    public function test_siswa_bisa_melihat_tapi_tidak_bisa_membuat_eksemplar(): void
    {
        $this->assertTrue($this->studentUser->can('viewAny', BookCopy::class));
        $this->assertFalse($this->studentUser->can('create', BookCopy::class));
    }

    public function test_barcode_duplikat_ditolak_database(): void
    {
        BookCopy::create([
            'book_id' => $this->book->id,
            'inventory_code' => 'BK-000001',
            'status' => BookCopyStatus::AVAILABLE,
        ]);

        $this->expectException(QueryException::class);

        BookCopy::create([
            'book_id' => $this->book->id,
            'inventory_code' => 'BK-000001',
            'status' => BookCopyStatus::AVAILABLE,
        ]);
    }

    public function test_aksi_hapus_disembunyikan_untuk_copy_yang_dipinjam(): void
    {
        $borrowed = BookCopy::create([
            'book_id' => $this->book->id,
            'inventory_code' => 'BK-000002',
            'status' => BookCopyStatus::BORROWED,
        ]);
        $available = BookCopy::create([
            'book_id' => $this->book->id,
            'inventory_code' => 'BK-000003',
            'status' => BookCopyStatus::AVAILABLE,
        ]);

        // Guard ada di resource, bukan di policy: policy tetap mengizinkan hapus untuk staf.
        $this->assertTrue($this->staff->can('delete', $borrowed));

        $this->actingAs($this->staff);

        Livewire::test(ListBookCopies::class)
            ->assertTableActionHidden('delete', $borrowed)
            ->assertTableActionVisible('delete', $available);
    }
}
