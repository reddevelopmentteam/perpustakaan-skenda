<?php

namespace Tests\Feature\Reports;

use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Reports\LoanReport;
use App\Filament\Pages\Reports\OverdueReport;
use App\Filament\Pages\Reports\ReturnReport;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanReportTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Student $studentA;

    private Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->studentA = Student::factory()->create(['class' => 'X-1']);
        $this->studentB = Student::factory()->create(['class' => 'XI-2']);

        $this->actingAs($this->staff);
    }

    private function buatPinjaman(Student $student, LoanStatus $status, array $dates = []): Loan
    {
        $book = Book::factory()->create(['stock' => 2, 'available_stock' => 2]);
        BookCopy::factory()->create(['book_id' => $book->id]);

        $loan = Loan::create(array_merge([
            'loan_number' => 'PJ-'.now()->format('Ymd').'-'.str_pad((string) (Loan::count() + 1), 4, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'status' => $status,
        ], $dates));

        LoanItem::create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'returned_quantity' => 0,
            'status' => LoanItemStatus::BORROWED,
        ]);

        return $loan;
    }

    public function test_petugas_dapat_membuka_ketiga_laporan(): void
    {
        $this->actingAs($this->staff);

        $this->get(LoanReport::getUrl())->assertOk();
        $this->get(ReturnReport::getUrl())->assertOk();
        $this->get(OverdueReport::getUrl())->assertOk();
    }

    public function test_filter_status_mempersempit_baris_tabel(): void
    {
        $borrowed = $this->buatPinjaman($this->studentA, LoanStatus::BORROWED, [
            'borrowed_at' => now()->subDays(2),
            'due_at' => now()->addDays(5),
        ]);
        $returned = $this->buatPinjaman($this->studentB, LoanStatus::RETURNED, [
            'borrowed_at' => now()->subDays(10),
            'due_at' => now()->subDays(3),
            'returned_at' => now()->subDays(2),
        ]);

        Livewire::test(LoanReport::class)
            ->assertCanSeeTableRecords([$borrowed, $returned])
            ->filterTable('status', LoanStatus::BORROWED->value)
            ->assertCanSeeTableRecords([$borrowed])
            ->assertCanNotSeeTableRecords([$returned]);
    }

    public function test_siswa_tidak_bisa_akses_laporan(): void
    {
        $studentUser = $this->studentA->user;
        $studentUser->role = UserRole::STUDENT;
        $studentUser->save();

        $this->actingAs($studentUser);

        $this->get(LoanReport::getUrl())->assertForbidden();
        $this->get(ReturnReport::getUrl())->assertForbidden();
        $this->get(OverdueReport::getUrl())->assertForbidden();
    }

    public function test_export_csv_berisi_bom_delimiter_dan_nomor_loan(): void
    {
        $loan = $this->buatPinjaman($this->studentA, LoanStatus::BORROWED, [
            'borrowed_at' => now()->subDay(),
            'due_at' => now()->addDays(6),
        ]);

        $response = Livewire::test(LoanReport::class)->call('exportCsv');

        $effects = $response->effects['download'] ?? null;
        $this->assertNotNull($effects, 'Export CSV harus menghasilkan file download.');
        $content = base64_decode($effects['content'] ?? '');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV harus diawali BOM UTF-8.');
        $this->assertStringContainsString($loan->loan_number, $content);

        $barisHeader = strtok(substr($content, 3), "\n");
        $this->assertStringContainsString(';', $barisHeader, 'Delimiter CSV harus titik koma (Excel Indonesia).');
    }

    public function test_export_csv_menghormati_filter_aktif(): void
    {
        $borrowed = $this->buatPinjaman($this->studentA, LoanStatus::BORROWED, [
            'borrowed_at' => now()->subDay(),
            'due_at' => now()->addDays(6),
        ]);
        $this->buatPinjaman($this->studentB, LoanStatus::RETURNED, [
            'borrowed_at' => now()->subDays(9),
            'due_at' => now()->subDays(2),
            'returned_at' => now()->subDay(),
        ]);

        $component = Livewire::test(LoanReport::class)
            ->filterTable('status', LoanStatus::BORROWED->value)
            ->call('exportCsv');

        $effects = $component->effects['download'] ?? null;
        $this->assertNotNull($effects, 'Export CSV harus menghasilkan file download.');
        $content = base64_decode($effects['content'] ?? '');

        $this->assertStringContainsString($borrowed->loan_number, $content);

        $baris = array_values(array_filter(explode("\n", trim($content))));
        $this->assertCount(2, $baris, 'CSV terfilter harus tepat 1 baris data + header.');
    }
}
