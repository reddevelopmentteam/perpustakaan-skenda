<?php

namespace Tests\Feature\Reports;

use App\Enums\BookCopyStatus;
use App\Enums\FineStatus;
use App\Enums\StudentStatus;
use App\Filament\Pages\Reports\BookReport;
use App\Filament\Pages\Reports\FineReport;
use App\Filament\Pages\Reports\MemberReport;
use App\Filament\Pages\Settings\ManageSettings;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uji 3 laporan stok (buku/anggota/denda) + halaman Pengaturan.
 * Satu file untuk keduanya agar tidak proliferasi file test laporan.
 */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $superAdmin;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->superAdmin = User::factory()->superAdmin()->create();

        $student = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $this->studentUser = $student->user;
        $this->studentUser->role = \App\Enums\UserRole::STUDENT;
        $this->studentUser->save();
    }

    public function test_staff_dapat_membuka_ketiga_laporan(): void
    {
        $this->actingAs($this->staff);

        $this->get(BookReport::getUrl())->assertOk();
        $this->get(MemberReport::getUrl())->assertOk();
        $this->get(FineReport::getUrl())->assertOk();
    }

    public function test_siswa_tidak_dapat_mengakses_laporan_administratif(): void
    {
        $this->actingAs($this->studentUser);

        $this->get(BookReport::getUrl())->assertForbidden();
        $this->get(MemberReport::getUrl())->assertForbidden();
        $this->get(FineReport::getUrl())->assertForbidden();
    }

    public function test_export_csv_buku_valid(): void
    {
        $book = Book::factory()->create([
            'title' => 'Laskar Pelangi',
            'stock' => 3,
            'available_stock' => 1,
        ]);
        BookCopy::factory()->count(2)->create(['book_id' => $book->id]);

        $this->actingAs($this->staff);

        $testable = Livewire::test(BookReport::class);

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $testable->instance()->exportCsv();

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV harus diawali BOM UTF-8.');
        $this->assertStringContainsString('Judul;Penulis;Kategori', $content);
        $this->assertStringContainsString('Laskar Pelangi', $content);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename=laporan-buku-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_filter_jenis_mempersempit_tabel_buku(): void
    {
        $habis = Book::factory()->create(['stock' => 0, 'available_stock' => 0]);
        $tersedia = Book::factory()->create(['stock' => 2, 'available_stock' => 2]);

        $this->actingAs($this->staff);

        Livewire::test(BookReport::class)
            ->filterTable('jenis', 'tersedia')
            ->assertCanSeeTableRecords([$tersedia])
            ->assertCanNotSeeTableRecords([$habis]);
    }

    public function test_super_admin_dapat_menyimpan_pengaturan_dan_diaudit(): void
    {
        Setting::set('fine_per_day', '1000');

        $this->actingAs($this->superAdmin);

        $this->get(ManageSettings::getUrl())->assertOk();

        Livewire::test(ManageSettings::class)
            ->fillForm(['fine_per_day' => '2000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2000', Setting::get('fine_per_day'));

        $audit = AuditLog::where('action', 'settings.updated')->latest()->firstOrFail();
        $this->assertSame($this->superAdmin->id, $audit->user_id);
        $this->assertSame('2000', $audit->new_values['fine_per_day'] ?? null);
    }

    public function test_staff_tidak_dapat_membuka_pengaturan(): void
    {
        $this->actingAs($this->staff);

        $this->get(ManageSettings::getUrl())->assertForbidden();
    }

    public function test_laporan_anggota_dan_denda_menampilkan_data_agregat(): void
    {
        $student = Student::factory()->create([
            'status' => StudentStatus::ACTIVE,
            'class' => 'X-1',
        ]);

        $loan = Loan::create([
            'loan_number' => 'PJ-20260101-0099',
            'student_id' => $student->id,
            'status' => \App\Enums\LoanStatus::OVERDUE,
        ]);

        Fine::create([
            'loan_id' => $loan->id,
            'student_id' => $student->id,
            'amount' => 5000,
            'late_days' => 5,
            'fine_rate_snapshot' => 1000,
            'status' => FineStatus::UNPAID,
        ]);

        $this->actingAs($this->staff);

        $this->get(MemberReport::getUrl())->assertOk();
        $this->get(FineReport::getUrl())->assertOk();

        Livewire::test(FineReport::class)
            ->filterTable('status', FineStatus::UNPAID->value)
            ->assertCanSeeTableRecords(Fine::where('status', FineStatus::UNPAID->value)->get());
    }
}
