<?php

namespace Tests\Feature\Loans;

use App\Enums\BookCopyStatus;
use App\Enums\FineStatus;
use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Enums\StudentStatus;
use App\Exceptions\LoanException;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\FineService;
use App\Services\LoanService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnFineTest extends TestCase
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
        Setting::set('max_loans_per_student', '10');
        Setting::set('max_extensions', '1');
        Setting::set('extension_days', '7');
        Setting::set('fine_per_day', '1000');
        Setting::set('grace_period_days', '0');
    }

    private function buatBukuDenganEksemplar(int $jumlah = 2): Book
    {
        /** @var Book $book */
        $book = Book::factory()->create([
            'stock' => $jumlah,
            'available_stock' => $jumlah,
        ]);

        BookCopy::factory()->count($jumlah)->create([
            'book_id' => $book->id,
            'status' => BookCopyStatus::AVAILABLE,
        ]);

        return $book;
    }

    private function pinjamPenuh(Student $student, Book $book): Loan
    {
        $loan = LoanService::request($student, [$book->id], $student->user);
        $loan = LoanService::approve($loan->refresh(), $this->staff);

        $copy = $book->copies()->where('status', BookCopyStatus::AVAILABLE)->firstOrFail();
        $itemId = $loan->refresh()->items->firstOrFail()->id;

        return LoanService::markBorrowed($loan, $this->staff, [$itemId => $copy->id]);
    }

    /**
     * @param  list<Book>  $books
     */
    private function pinjamBanyak(Student $student, array $books): Loan
    {
        $bookIds = collect($books)->pluck('id')->all();

        $loan = LoanService::request($student, $bookIds, $student->user);
        $loan = LoanService::approve($loan->refresh(), $this->staff);

        $map = [];

        foreach ($loan->refresh()->items as $item) {
            $copy = BookCopy::query()
                ->where('book_id', $item->book_id)
                ->where('status', BookCopyStatus::AVAILABLE)
                ->firstOrFail();
            $map[$item->id] = $copy->id;
        }

        return LoanService::markBorrowed($loan, $this->staff, $map);
    }

    private function buatTerlambat(Loan $loan, int $hariTerlambat = 3, int $lamaPinjamHari = 10): Loan
    {
        $due = now()->startOfDay()->subDays($hariTerlambat);
        $borrowed = now()->startOfDay()->subDays($lamaPinjamHari);

        $loan->update(['borrowed_at' => $borrowed, 'due_at' => $due]);
        $loan->items()->update(['due_at' => $due]);

        return $loan->refresh();
    }

    /** @return list<int> */
    private function copyIds(Loan $loan): array
    {
        return $loan->refresh()->items->pluck('book_copy_id')->all();
    }

    public function test_pengembalian_tepat_waktu_tanpa_denda(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->pinjamPenuh($this->student, $book);

        $this->assertSame(1, $book->refresh()->available_stock, 'Stok berkurang 1 saat BORROWED.');

        $returned = ReturnService::process($loan, $this->staff, [], 'Dikembalikan dalam kondisi baik.');

        $this->assertEquals(LoanStatus::RETURNED, $returned->status, 'Loan tepat waktu harus RETURNED.');
        $this->assertNotNull($returned->returned_at, 'returned_at wajib terisi.');
        $this->assertSame(2, $book->refresh()->available_stock, 'Stok kembali penuh setelah pengembalian.');

        foreach ($returned->refresh()->items as $item) {
            $this->assertEquals(LoanItemStatus::RETURNED, $item->status, 'Setiap item harus RETURNED.');
            $this->assertNotNull($item->returned_at, 'Item harus mencatat returned_at.');
            $this->assertEquals(
                BookCopyStatus::AVAILABLE,
                $item->copy->refresh()->status,
                'Eksemplar kembali AVAILABLE.'
            );
        }

        $this->assertDatabaseMissing('fines', ['loan_id' => $loan->id]);
        // Pengembalian tepat waktu TIDAK boleh menerbitkan denda.
        $this->assertNull(FineService::issueForLoan($returned->refresh()), 'issueForLoan loan tepat waktu harus null.');
    }

    public function test_pengembalian_ganda_tetap_idempotent(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->pinjamPenuh($this->student, $book);
        $copyIds = $this->copyIds($loan);

        $pertama = ReturnService::process($loan, $this->staff);
        $stokSetelahPertama = $book->refresh()->available_stock;

        $kedua = ReturnService::process($pertama->refresh(), $this->staff, $copyIds);

        $this->assertEquals(LoanStatus::RETURNED, $kedua->status, 'Proses kedua harus tetap RETURNED.');
        $this->assertSame($stokSetelahPertama, $book->refresh()->available_stock, 'Stok hanya naik 1x walau diproses 2x.');
        $this->assertSame(2, $book->refresh()->available_stock, 'Stok akhir harus tepat 2, bukan 3.');
        $this->assertSame(0, Fine::query()->where('loan_id', $loan->id)->count(), 'Tidak ada denda yang terbit dari proses ganda.');
    }

    public function test_pengembalian_sebagian_satu_dari_dua_buku(): void
    {
        $bookA = $this->buatBukuDenganEksemplar(2);
        $bookB = $this->buatBukuDenganEksemplar(2);
        $loan = $this->pinjamBanyak($this->student, [$bookA, $bookB]);

        $copyA = $loan->refresh()->items->firstWhere('book_id', $bookA->id)->book_copy_id;
        $copyB = $loan->refresh()->items->firstWhere('book_id', $bookB->id)->book_copy_id;

        $hasil = ReturnService::process($loan, $this->staff, [$copyA]);

        $this->assertEquals(LoanStatus::PARTIALLY_RETURNED, $hasil->status, 'Kembali 1 dari 2 harus PARTIALLY_RETURNED.');
        $this->assertNull($hasil->returned_at, 'returned_at loan belum terisi saat sebagian.');
        $this->assertSame(2, $bookA->refresh()->available_stock, 'Stok buku yang dikembalikan harus +1.');
        $this->assertSame(1, $bookB->refresh()->available_stock, 'Stok buku yang belum dikembalikan harus tetap.');

        $itemA = $hasil->refresh()->items->firstWhere('book_id', $bookA->id);
        $itemB = $hasil->refresh()->items->firstWhere('book_id', $bookB->id);

        $this->assertEquals(LoanItemStatus::RETURNED, $itemA->status, 'Item yang dikembalikan harus RETURNED.');
        $this->assertEquals(LoanItemStatus::BORROWED, $itemB->status, 'Item lain harus tetap BORROWED.');
        $this->assertEquals(BookCopyStatus::AVAILABLE, BookCopy::find($copyA)->status, 'Copy yang kembali harus AVAILABLE.');
        $this->assertEquals(BookCopyStatus::BORROWED, BookCopy::find($copyB)->status, 'Copy lain harus tetap BORROWED.');
    }

    public function test_keterlambatan_menerbitkan_denda_unpaid(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        $calc = FineService::calculate($loan);

        $this->assertSame(3, $calc['late_days'], 'Keterlambatan harus 3 hari.');
        $this->assertSame(3000, $calc['amount'], 'Denda harus 3 x tarif 1000.');
        $this->assertSame(1000, $calc['rate'], 'Rate harus sama dengan setting fine_per_day.');

        $hasil = ReturnService::process($loan, $this->staff);

        $this->assertEquals(LoanStatus::RETURNED, $hasil->status, 'Loan terlambat yang dikembalikan harus RETURNED.');

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

        $this->assertEquals(FineStatus::UNPAID, $fine->status, 'Denda baru harus UNPAID.');
        $this->assertSame(3, (int) $fine->late_days, 'late_days tersimpan harus 3.');
        $this->assertSame(3000, (int) $fine->amount, 'amount harus 3 x tarif.');
        $this->assertSame(1000, (int) $fine->fine_rate_snapshot, 'rate_snapshot harus sama dengan tarif saat dihitung.');
    }

    public function test_grace_period_mengurangi_hari_keterlambatan(): void
    {
        Setting::set('grace_period_days', '1');

        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        $hasil = ReturnService::process($loan, $this->staff);

        $this->assertEquals(LoanStatus::RETURNED, $hasil->status);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

        $this->assertSame(2, (int) $fine->late_days, 'Grace 1 hari: keterlambatan 3 hari dihitung 2 hari.');
        $this->assertSame(2000, (int) $fine->amount, 'Denda harus 2 x tarif 1000.');
    }

    public function test_batas_maksimum_denda(): void
    {
        Setting::set('max_fine', '1000');

        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

        $this->assertSame(3, (int) $fine->late_days, 'late_days tetap 3 walau dibatasi max.');
        $this->assertSame(1000, (int) $fine->amount, 'Denda 3000 harus dibatasi max_fine 1000.');
    }

    public function test_tarif_denda_snapshot_tidak_berubah_setelah_setting_diubah(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();
        $this->assertSame(3000, (int) $fine->amount);

        Setting::set('fine_per_day', '5000');

        $fine = $fine->refresh();

        $this->assertSame(3000, (int) $fine->amount, 'Denda yang sudah terbit tidak boleh berubah saat tarif diubah.');
        $this->assertSame(1000, (int) $fine->fine_rate_snapshot, 'Snapshot tarif harus tetap tarif lama.');
    }

    public function test_pembayaran_denda_menandai_lunas(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

        $lunas = FineService::pay($fine, $this->staff);

        $this->assertEquals(FineStatus::PAID, $lunas->status, 'Denda harus PAID setelah dibayar.');
        $this->assertNotNull($lunas->paid_at, 'paid_at wajib terisi.');
        $this->assertSame($this->staff->id, $lunas->paid_by, 'paid_by harus kasir yang memproses.');
    }

    public function test_pembayaran_ganda_melempar_exception(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();
        FineService::pay($fine->refresh(), $this->staff);

        try {
            FineService::pay($fine->refresh(), $this->staff);
            $this->fail('Membayar denda yang sudah lunas harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage(), 'Pesan exception harus jelas dan berbahasa Indonesia.');
        }

        $this->assertEquals(FineStatus::PAID, $fine->refresh()->status, 'Status denda harus tetap PAID.');
    }

    public function test_pembebasan_denda(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

        $bebas = FineService::waive($fine, $this->staff, 'Kebijakan keringanan.');

        $this->assertEquals(FineStatus::WAIVED, $bebas->status, 'Denda harus WAIVED setelah dibebaskan.');
    }

    public function test_pembebasan_denda_lunas_melempar_exception(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        ReturnService::process($loan, $this->staff);

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();
        FineService::pay($fine->refresh(), $this->staff);

        try {
            FineService::waive($fine->refresh(), $this->staff);
            $this->fail('Membebaskan denda yang sudah lunas harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage(), 'Pesan exception harus jelas dan berbahasa Indonesia.');
        }

        $this->assertEquals(FineStatus::PAID, $fine->refresh()->status, 'Status denda harus tetap PAID.');
    }

    public function test_issue_for_loan_idempotent(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->buatTerlambat($this->pinjamPenuh($this->student, $book), 3, 10);

        $pertama = FineService::issueForLoan($loan);
        $kedua = FineService::issueForLoan($loan->refresh());

        $this->assertNotNull($pertama, 'issueForLoan loan terlambat harus menerbitkan denda.');
        $this->assertNotNull($kedua);
        $this->assertSame($pertama->id, $kedua->id, 'Pemanggilan kedua harus mengembalikan baris yang sama (update, bukan insert).');
        $this->assertSame(1, Fine::query()->where('loan_id', $loan->id)->count(), 'Tetap hanya 1 baris denda.');
    }

    public function test_scheduler_menandai_overdue_dan_menerbitkan_denda(): void
    {
        $bookTerlambat = $this->buatBukuDenganEksemplar(2);
        $terlambat = $this->buatTerlambat($this->pinjamPenuh($this->student, $bookTerlambat), 3, 10);

        $studentLain = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $bookAman = $this->buatBukuDenganEksemplar(2);
        $aman = $this->pinjamPenuh($studentLain, $bookAman);

        $this->artisan('loans:mark-overdue')->assertSuccessful();

        $terlambat = $terlambat->refresh();

        $this->assertEquals(LoanStatus::OVERDUE, $terlambat->status, 'Loan lewat jatuh tempo harus OVERDUE.');

        /** @var Fine $fine */
        $fine = Fine::query()->where('loan_id', $terlambat->id)->firstOrFail();

        $this->assertEquals(FineStatus::UNPAID, $fine->status, 'Scheduler harus menerbitkan denda UNPAID.');
        $this->assertSame(3, (int) $fine->late_days);
        $this->assertSame(3000, (int) $fine->amount);

        $this->artisan('loans:mark-overdue')->assertSuccessful();

        $this->assertSame(1, Fine::query()->where('loan_id', $terlambat->id)->count(), 'Scheduler 2x tidak boleh menggandakan denda.');
        $this->assertEquals(LoanStatus::OVERDUE, $terlambat->refresh()->status);

        $this->assertEquals(LoanStatus::BORROWED, $aman->refresh()->status, 'Loan yang belum jatuh tempo tidak boleh tersentuh.');
        $this->assertSame(0, Fine::query()->where('loan_id', $aman->id)->count(), 'Loan yang belum jatuh tempo tidak boleh kena denda.');
    }

    public function test_validasi_pengembalian_menolak_loan_belum_dipinjam_dan_copy_asing(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $pending = LoanService::request($this->student, [$book->id], $this->student->user);

        try {
            ReturnService::process($pending, $this->staff, []);
            $this->fail('Memproses loan PENDING harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage(), 'Pesan exception harus jelas dan berbahasa Indonesia.');
        }

        $this->assertEquals(LoanStatus::PENDING, $pending->refresh()->status, 'Loan PENDING harus tetap PENDING.');

        $studentLain = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $bookAsing = $this->buatBukuDenganEksemplar(2);
        $copyAsing = $bookAsing->copies()->where('status', BookCopyStatus::AVAILABLE)->firstOrFail();

        $dipinjam = $this->pinjamPenuh($studentLain, $this->buatBukuDenganEksemplar(2));

        try {
            ReturnService::process($dipinjam, $this->staff, [$copyAsing->id]);
            $this->fail('Copy yang tidak ada di loan harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage(), 'Pesan exception harus jelas dan berbahasa Indonesia.');
        }

        $this->assertEquals(LoanStatus::BORROWED, $dipinjam->refresh()->status, 'Loan harus tetap BORROWED setelah copy asing ditolak.');
    }

    public function test_audit_dan_notifikasi_pengembalian(): void
    {
        $book = $this->buatBukuDenganEksemplar(2);
        $loan = $this->pinjamPenuh($this->student, $book);

        ReturnService::process($loan, $this->staff);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loans.returned',
            'auditable_type' => Loan::class,
            'auditable_id' => $loan->id,
        ]);
        // Audit loans.returned wajib tercatat.

        $this->assertDatabaseHas('notifications', [
            'type' => 'loan.returned',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->student->user->id,
        ]);
        // Siswa harus menerima notifikasi loan.returned.

        $studentTerlambat = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $bookTerlambat = $this->buatBukuDenganEksemplar(2);
        $terlambat = $this->buatTerlambat($this->pinjamPenuh($studentTerlambat, $bookTerlambat), 3, 10);

        ReturnService::process($terlambat, $this->staff);

        $this->assertDatabaseHas('notifications', [
            'type' => 'fine.issued',
            'notifiable_type' => User::class,
            'notifiable_id' => $studentTerlambat->user->id,
        ]);
        // Siswa harus menerima notifikasi fine.issued saat denda terbit.
    }

    public function test_perubahan_setting_durasi_tidak_mengubah_due_aktif(): void
    {
        $t0 = now()->startOfDay();

        try {
            $this->travelTo($t0);

            $book = $this->buatBukuDenganEksemplar(2);
            $loan = $this->pinjamPenuh($this->student, $book);
            $dueAwal = $loan->refresh()->due_at->copy();

            $this->assertSame(
                $t0->copy()->addDays(7)->toDateTimeString(),
                $dueAwal->toDateTimeString(),
                'Due awal harus +7 hari dari saat pinjam.'
            );

            Setting::set('loan_duration_days', '14');

            $this->travelTo($t0->copy()->addDays(10));

            $hasil = ReturnService::process($loan->refresh(), $this->staff);

            $this->assertSame(
                $dueAwal->toDateTimeString(),
                $hasil->due_at->toDateTimeString(),
                'Due loan aktif tidak boleh berubah saat setting durasi diubah.'
            );

            /** @var Fine $fine */
            $fine = Fine::query()->where('loan_id', $loan->id)->firstOrFail();

            $this->assertSame(3, (int) $fine->late_days, 'Denda mengacu due lama (+7 hari), bukan setting baru.');
            $this->assertSame(3000, (int) $fine->amount);
        } finally {
            $this->travelBack();
        }
    }
}
