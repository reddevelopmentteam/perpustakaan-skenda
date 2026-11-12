<?php

namespace Tests\Feature\Loans;

use App\Enums\BookCopyStatus;
use App\Enums\ExtensionStatus;
use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentStatus;
use App\Exceptions\LoanException;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\LoanExtensionService;
use App\Services\LoanNumberService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->student = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        Setting::set('loan_duration_days', '7');
        Setting::set('extension_days', '7');
        Setting::set('max_loans_per_student', '3');
        Setting::set('max_extensions', '1');
    }

    private function buatBukuDenganEksemplar(int $jumlah = 2, array $bookOverrides = []): Book
    {
        /** @var Book $book */
        $book = Book::factory()->create(array_merge([
            'stock' => $jumlah,
            'available_stock' => $jumlah,
        ], $bookOverrides));

        BookCopy::factory()->count($jumlah)->create([
            'book_id' => $book->id,
            'status' => BookCopyStatus::AVAILABLE,
        ]);

        return $book;
    }

    private function ajukanPinjaman(Student $student, array $bookIds, ?string $notes = 'Untuk tugas sekolah'): Loan
    {
        return LoanService::request($student, $bookIds, $student->user, $notes);
    }

    private function pinjamPenuh(Student $student, Book $book, ?BookCopy $copy = null): Loan
    {
        $loan = $this->ajukanPinjaman($student, [$book->id]);
        $loan = LoanService::approve($loan, $this->staff);

        $copy ??= $book->copies()->where('status', BookCopyStatus::AVAILABLE)->firstOrFail();
        $itemId = $loan->refresh()->items->firstOrFail()->id;

        return LoanService::markBorrowed($loan, $this->staff, [$itemId => $copy->id]);
    }

    public function test_siswa_aktif_bisa_mengajukan_peminjaman(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);

        $loan = $this->ajukanPinjaman($this->student, [$book->id]);

        $this->assertEquals(LoanStatus::PENDING, $loan->status, 'Loan baru harus berstatus PENDING.');
        $this->assertCount(1, $loan->items, 'Loan harus memiliki 1 item.');
        $this->assertEquals(LoanItemStatus::PENDING, $loan->items->first()->status, 'Item loan baru harus PENDING.');
        $this->assertNull($loan->items->first()->book_copy_id, 'Eksemplar fisik (copy) belum ditetapkan saat request.');
        $this->assertMatchesRegularExpression(
            '/\APJ-\d{8}-\d{4}\z/',
            $loan->loan_number,
            'Nomor transaksi harus berformat PJ-YYYYMMDD-XXXX.'
        );
        $this->assertSame(now()->format('Ymd'), substr($loan->loan_number, 3, 8), 'Tanggal pada nomor harus tanggal hari ini.');
        $this->assertSame(2, $book->refresh()->available_stock, 'Stok TIDAK boleh berubah saat pengajuan (baru keluar saat BORROWED).');
    }

    public function test_request_ditolak_bila_stok_kosong(): void
    {
        $book = $this->buatBukuDenganEksemplar(0, ['stock' => 0, 'available_stock' => 0]);

        $this->expectException(LoanException::class);

        $this->ajukanPinjaman($this->student, [$book->id]);
    }

    public function test_request_ditolak_bila_melebihi_limit_pinjaman_aktif(): void
    {
        Setting::set('max_loans_per_student', '1');

        $bookLama = $this->buatBukuDenganEksemplar();
        $this->pinjamPenuh($this->student, $bookLama);

        $bookBaru = $this->buatBukuDenganEksemplar();

        $this->expectException(LoanException::class);

        $this->ajukanPinjaman($this->student, [$bookBaru->id]);
    }

    public function test_request_ditolak_bila_siswa_diblokir(): void
    {
        $this->student->update(['status' => StudentStatus::BLOCKED]);
        $book = $this->buatBukuDenganEksemplar();

        $this->expectException(LoanException::class);

        $this->ajukanPinjaman($this->student->refresh(), [$book->id]);
    }

    public function test_request_ditolak_bila_siswa_punya_pinjaman_terlambat(): void
    {
        Loan::create([
            'loan_number' => 'PJ-20000101-0001',
            'student_id' => $this->student->id,
            'status' => LoanStatus::OVERDUE,
            'requested_at' => now()->subDays(20),
        ]);

        $book = $this->buatBukuDenganEksemplar();

        $this->expectException(LoanException::class);

        $this->ajukanPinjaman($this->student, [$book->id]);
    }

    public function test_staff_bisa_menyetujui_pengajuan_pending(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);

        $approved = LoanService::approve($loan, $this->staff);

        $this->assertEquals(LoanStatus::APPROVED, $approved->status, 'Loan harus berstatus APPROVED setelah disetujui.');
        $this->assertEquals($this->staff->id, $approved->approved_by, 'approved_by harus diisi staf penyetuju.');
        $this->assertNotNull($approved->approved_at, 'approved_at harus terisi.');
        $this->assertSame(2, $book->refresh()->available_stock, 'Stok tetap TIDAK berubah saat approve.');
    }

    public function test_approve_ditolak_bila_loan_bukan_pending(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);
        $loan = LoanService::approve($loan, $this->staff);

        $this->expectException(LoanException::class);

        LoanService::approve($loan, $this->staff);
    }

    public function test_reject_wajib_disertai_alasan(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);

        try {
            LoanService::reject($loan, $this->staff, '');
            $this->fail('Reject tanpa alasan harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage(), 'Pesan exception penolakan harus berbahasa Indonesia dan jelas.');
        }

        $rejected = LoanService::reject($loan->refresh(), $this->staff, 'Stok untuk kelas lain.');

        $this->assertEquals(LoanStatus::REJECTED, $rejected->status, 'Loan harus berstatus REJECTED.');
        $this->assertSame('Stok untuk kelas lain.', $rejected->rejection_reason, 'Alasan penolakan harus tersimpan.');
        $this->assertTrue(
            $rejected->refresh()->items->every(fn ($item) => $item->status === LoanItemStatus::CANCELLED),
            'Semua item loan yang ditolak harus CANCELLED.'
        );
    }

    public function test_mark_borrowed_berhasil_menyerahkan_buku(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);
        $loan = LoanService::approve($loan, $this->staff);

        $copy = $book->copies()->where('status', BookCopyStatus::AVAILABLE)->firstOrFail();
        $itemId = $loan->items->firstOrFail()->id;

        $borrowed = LoanService::markBorrowed($loan, $this->staff, [$itemId => $copy->id]);

        $this->assertEquals(LoanStatus::BORROWED, $borrowed->status, 'Loan harus berstatus BORROWED.');
        $this->assertNotNull($borrowed->borrowed_at, 'borrowed_at harus terisi.');
        $this->assertEquals(BookCopyStatus::BORROWED, $copy->refresh()->status, 'Eksemplar harus berstatus BORROWED.');
        $this->assertSame(1, $book->refresh()->available_stock, 'available_stock harus berkurang 1 saat buku diserahkan.');

        $item = $borrowed->refresh()->items->firstOrFail();
        $this->assertEquals(LoanItemStatus::BORROWED, $item->status, 'Item harus berstatus BORROWED.');
        $this->assertEquals($copy->id, $item->book_copy_id, 'Item harus menunjuk eksemplar yang diserahkan.');

        $diharapkan = $borrowed->borrowed_at->copy()->addDays(7)->timestamp;
        $this->assertSame($diharapkan, $borrowed->due_at->timestamp, 'due_at harus borrowed_at + loan_duration_days (7 hari).');
    }

    public function test_mark_borrowed_ditolak_bila_eksemplar_milik_buku_lain(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $bookLain = $this->buatBukuDenganEksemplar();
        $copyLain = $bookLain->copies()->firstOrFail();

        $loan = $this->ajukanPinjaman($this->student, [$book->id]);
        $loan = LoanService::approve($loan, $this->staff);
        $itemId = $loan->items->firstOrFail()->id;

        try {
            LoanService::markBorrowed($loan, $this->staff, [$itemId => $copyLain->id]);
            $this->fail('Eksemplar milik buku lain harus melempar LoanException.');
        } catch (LoanException) {
            $this->assertSame(2, $book->refresh()->available_stock, 'Stok harus tetap bila penyerahan gagal.');
            $this->assertSame(2, $bookLain->refresh()->available_stock, 'Stok buku lain ikut tetap.');
        }
    }

    public function test_mark_borrowed_ditolak_bila_eksemplar_sudah_dipinjam(): void
    {
        $book = $this->buatBukuDenganEksemplar(1);
        $copy = $book->copies()->firstOrFail();
        $copy->update(['status' => BookCopyStatus::BORROWED]);

        $loan = $this->ajukanPinjaman($this->student, [$book->id]);
        $loan = LoanService::approve($loan, $this->staff);
        $itemId = $loan->items->firstOrFail()->id;

        $this->expectException(LoanException::class);

        try {
            LoanService::markBorrowed($loan, $this->staff, [$itemId => $copy->id]);
        } finally {
            $this->assertSame(1, $book->refresh()->available_stock, 'Stok harus tetap bila eksemplar tidak tersedia.');
        }
    }

    public function test_cancel_pending_membatalkan_tanpa_mengubah_stok(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);

        $cancelled = LoanService::cancel($loan, $this->student->user);

        $this->assertEquals(LoanStatus::CANCELLED, $cancelled->status, 'Loan PENDING harus bisa dibatalkan.');
        $this->assertSame(2, $book->refresh()->available_stock, 'Stok tetap karena buku belum diserahkan.');
    }

    public function test_cancel_ditolak_bila_loan_sudah_borrowed(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->pinjamPenuh($this->student, $book);

        $this->expectException(LoanException::class);

        try {
            LoanService::cancel($loan, $this->student->user);
        } finally {
            $this->assertEquals(LoanStatus::BORROWED, $loan->refresh()->status, 'Loan BORROWED harus tetap BORROWED.');
        }
    }

    public function test_nomor_transaksi_unik_dan_terurut_naik(): void
    {
        $pertama = LoanNumberService::forLoan();
        $kedua = LoanNumberService::forLoan();

        $this->assertMatchesRegularExpression('/\APJ-\d{8}-\d{4}\z/', $pertama, 'Nomor loan berformat PJ-YYYYMMDD-XXXX.');
        $this->assertMatchesRegularExpression('/\APJ-\d{8}-\d{4}\z/', $kedua, 'Nomor loan berformat PJ-YYYYMMDD-XXXX.');
        $this->assertNotSame($pertama, $kedua, 'Dua nomor berurutan tidak boleh sama (anti duplikat race).');
        $this->assertGreaterThan($pertama, $kedua, 'Nomor kedua harus lebih besar (terurut naik).');

        $reservasi = LoanNumberService::forReservation();
        $this->assertMatchesRegularExpression('/\ARSV-\d{8}-\d{4}\z/', $reservasi, 'Nomor reservasi berformat RSV-YYYYMMDD-XXXX.');
    }

    public function test_dua_loan_berurutan_mendapat_nomor_berbeda(): void
    {
        $bukuA = $this->buatBukuDenganEksemplar();
        $bukuB = $this->buatBukuDenganEksemplar();

        $loanA = $this->ajukanPinjaman($this->student, [$bukuA->id]);
        $loanB = $this->ajukanPinjaman($this->student, [$bukuB->id]);

        $this->assertNotSame($loanA->loan_number, $loanB->loan_number, 'Nomor dua loan berurutan harus berbeda.');
        $this->assertGreaterThan($loanA->loan_number, $loanB->loan_number, 'Nomor loan kedua harus terurut naik.');
    }

    public function test_extension_request_berhasil_tanpa_mengubah_due(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->pinjamPenuh($this->student, $book);
        $dueSemula = $loan->due_at->copy();

        $ext = LoanExtensionService::request($loan, $this->student->user, 'Belum selesai membaca.');

        $this->assertEquals(ExtensionStatus::PENDING, $ext->status, 'Pengajuan perpanjangan baru harus PENDING.');
        $this->assertTrue(
            $loan->refresh()->due_at->equalTo($dueSemula),
            'due_at TIDAK boleh berubah saat pengajuan (baru maju saat approve).'
        );
    }

    public function test_extension_request_kedua_ditolak_selama_masih_pending(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->pinjamPenuh($this->student, $book);

        LoanExtensionService::request($loan, $this->student->user, 'Pengajuan pertama.');

        $this->expectException(LoanException::class);

        LoanExtensionService::request($loan->refresh(), $this->student->user, 'Pengajuan kedua.');
    }


    public function test_extension_approve_memajukan_jatuh_tempo(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->pinjamPenuh($this->student, $book);
        $dueSemula = $loan->due_at->copy();

        $ext = LoanExtensionService::request($loan, $this->student->user, 'Belum selesai.');
        $hasil = LoanExtensionService::approve($ext, $this->staff);

        $diharapkan = $dueSemula->copy()->addDays(7)->timestamp;
        $this->assertSame($diharapkan, $hasil->due_at->timestamp, 'due_at harus maju extension_days (7 hari).');
        $this->assertSame(1, $hasil->extension_count, 'extension_count harus bertambah 1.');
        $this->assertEquals(ExtensionStatus::APPROVED, $ext->refresh()->status, 'Extension harus APPROVED.');
    }

    public function test_extension_reject_menolak_pengajuan(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->pinjamPenuh($this->student, $book);
        $dueSemula = $loan->due_at->copy();

        $ext = LoanExtensionService::request($loan, $this->student->user, 'Belum selesai.');
        $ditolak = LoanExtensionService::reject($ext, $this->staff, 'Buku dibutuhkan siswa lain.');

        $this->assertEquals(ExtensionStatus::REJECTED, $ditolak->status, 'Extension harus REJECTED.');
        $this->assertTrue(
            $loan->refresh()->due_at->equalTo($dueSemula),
            'due_at harus tetap bila perpanjangan ditolak.'
        );
    }

    public function test_find_by_barcode_mengembalikan_buku_yang_benar(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $copy = $book->copies()->firstOrFail();

        $ditemukan = LoanService::findByInventoryCode($copy->inventory_code);

        $this->assertNotNull($ditemukan, 'Nomor induk terdaftar harus menemukan buku.');
        $this->assertSame($book->id, $ditemukan->id, 'Nomor induk harus memetakan ke buku pemilik eksemplar.');
        $this->assertNull(LoanService::findByInventoryCode('BK-TIDAK-ADA'), 'Nomor induk tak dikenal harus mengembalikan null.');
    }

    public function test_audit_dan_notifikasi_tertulis_untuk_request_dan_approve(): void
    {
        $book = $this->buatBukuDenganEksemplar();
        $loan = $this->ajukanPinjaman($this->student, [$book->id]);
        LoanService::approve($loan, $this->staff);

        $this->assertTrue(
            AuditLog::where('action', 'loans.requested')->where('auditable_id', $loan->id)->exists(),
            'Audit loans.requested harus tercatat.'
        );
        $this->assertTrue(
            AuditLog::where('action', 'loans.approved')->where('auditable_id', $loan->id)->exists(),
            'Audit loans.approved harus tercatat.'
        );
        $this->assertTrue(
            DB::table('notifications')->where('type', 'loan.requested')->where('notifiable_id', $this->staff->id)->exists(),
            'Notifikasi loan.requested harus terkirim ke staf.'
        );
        $this->assertTrue(
            DB::table('notifications')->where('type', 'loan.approved')->where('notifiable_id', $this->student->user->id)->exists(),
            'Notifikasi loan.approved harus terkirim ke siswa pemilik.'
        );
    }
}
