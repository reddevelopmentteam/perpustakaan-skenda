<?php

namespace Tests\Feature\Loans;

use App\Enums\BookCopyStatus;
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
use App\Services\LoanService;
use App\Services\ReservationService;
use App\Services\ReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
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
        Setting::set('reservation_hold_days', '3');
    }

    private function buatBuku(int $jumlah = 1, int $available = 1): Book
    {
        /** @var Book $book */
        $book = Book::factory()->create([
            'stock' => $jumlah,
            'available_stock' => $available,
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
        $loan = LoanService::approve($loan, $this->staff);

        $copy = $book->copies()->where('status', BookCopyStatus::AVAILABLE)->firstOrFail();
        $itemId = $loan->refresh()->items->firstOrFail()->id;

        return LoanService::markBorrowed($loan, $this->staff, [$itemId => $copy->id]);
    }

    public function test_reserve_saat_stok_habis_masuk_antrean_waiting(): void
    {
        $book = $this->buatBuku(0, 0);

        $reservation = ReservationService::reserve($this->student, $book->id, $this->student->user);

        $this->assertEquals(ReservationStatus::WAITING, $reservation->status, 'Reservasi baru harus WAITING.');
        $this->assertSame(1, $reservation->queue_position, 'Reservasi pertama harus antrean ke-1.');
        $this->assertMatchesRegularExpression(
            '/\ARSV-\d{8}-\d{4}\z/',
            $reservation->reservation_number,
            'Nomor reservasi harus berformat RSV-YYYYMMDD-XXXX.'
        );
        $this->assertSame(now()->format('Ymd'), substr($reservation->reservation_number, 4, 8), 'Tanggal pada nomor harus tanggal hari ini.');
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'reservations.created')
                ->where('auditable_type', Reservation::class)
                ->where('auditable_id', $reservation->id)
                ->exists(),
            'Audit reservations.created harus tercatat.'
        );
    }

    public function test_antrean_dua_siswa_cancel_lalu_promote(): void
    {
        $book = $this->buatBuku(0, 0);
        $kedua = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        Setting::set('reservation_hold_days', '3');

        $pertama = ReservationService::reserve($this->student, $book->id, $this->student->user);
        $antreKedua = ReservationService::reserve($kedua, $book->id, $kedua->user);

        $this->assertSame(1, $pertama->queue_position, 'Pendaftar pertama antrean ke-1.');
        $this->assertSame(2, $antreKedua->queue_position, 'Pendaftar kedua antrean ke-2.');

        $dibatalkan = ReservationService::cancel($pertama, $this->student->user);

        $this->assertEquals(ReservationStatus::CANCELLED, $dibatalkan->status, 'Reservasi yang dibatalkan harus CANCELLED.');

        $dipromosikan = ReservationService::promoteNext($book, $this->staff);

        $this->assertNotNull($dipromosikan, 'promoteNext harus mengembalikan reservasi berikutnya.');
        $this->assertSame($antreKedua->id, $dipromosikan->id, 'Pendaftar kedua yang harus dipromosikan.');
        $this->assertEquals(ReservationStatus::AVAILABLE, $dipromosikan->status, 'Reservasi terdepan harus AVAILABLE.');
        $this->assertNotNull($dipromosikan->expires_at, 'expires_at harus terisi saat promosi.');
        $this->assertTrue($dipromosikan->expires_at->gt(now()), 'expires_at harus di masa depan.');
        $this->assertSame(
            now()->addDays(3)->format('Y-m-d'),
            $dipromosikan->expires_at->format('Y-m-d'),
            'expires_at harus now + reservation_hold_days.'
        );
    }

    public function test_duplikat_pinjam_aktif_dan_blocked_ditolak(): void
    {
        $book = $this->buatBuku(0, 0);

        ReservationService::reserve($this->student, $book->id, $this->student->user);

        try {
            ReservationService::reserve($this->student, $book->id, $this->student->user);
            $this->fail('Reservasi aktif ganda harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $bukuPinjaman = $this->buatBuku(1, 1);
        $this->pinjamPenuh($this->student, $bukuPinjaman);

        try {
            ReservationService::reserve($this->student, $bukuPinjaman->id, $this->student->user);
            $this->fail('Reservasi buku yang sedang dipinjam pemohon harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $diblokir = Student::factory()->create(['status' => StudentStatus::BLOCKED]);
        $bukuLain = $this->buatBuku(1, 1);

        try {
            ReservationService::reserve($diblokir, $bukuLain->id);
            $this->fail('Siswa BLOCKED harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_fulfill_hanya_dari_available(): void
    {
        $book = $this->buatBuku(0, 0);

        $reservation = ReservationService::reserve($this->student, $book->id, $this->student->user);
        $tersedia = ReservationService::promoteNext($book, $this->staff);

        $this->assertSame($reservation->id, $tersedia->id);

        $selesai = ReservationService::fulfill($tersedia, $this->staff);

        $this->assertEquals(ReservationStatus::FULFILLED, $selesai->status, 'AVAILABLE -> FULFILLED.');
        $this->assertNotNull($selesai->fulfilled_at, 'fulfilled_at harus terisi.');

        $menunggu = ReservationService::reserve(
            Student::factory()->create(['status' => StudentStatus::ACTIVE]),
            $book->id
        );

        try {
            ReservationService::fulfill($menunggu, $this->staff);
            $this->fail('fulfill WAITING harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_expire_oper_antrean_dan_idempotent(): void
    {
        $book = $this->buatBuku(0, 0);
        $kedua = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        $pertama = ReservationService::reserve($this->student, $book->id, $this->student->user);
        $antreKedua = ReservationService::reserve($kedua, $book->id, $kedua->user);

        $tersedia = ReservationService::promoteNext($book, $this->staff);
        $this->assertSame($pertama->id, $tersedia->id);

        $tersedia->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, ReservationService::expireOverdue(), 'Satu reservasi lewat batas harus kedaluwarsa.');

        $this->assertEquals(ReservationStatus::EXPIRED, $pertama->refresh()->status, 'Yang lewat batas harus EXPIRED.');

        $operan = $antreKedua->refresh();
        $this->assertEquals(ReservationStatus::AVAILABLE, $operan->status, 'Antrean berikutnya otomatis AVAILABLE.');
        $this->assertNotNull($operan->expires_at, 'Operan antrean harus punya expires_at baru.');
        $this->assertTrue($operan->expires_at->gt(now()), 'expires_at operan harus di masa depan.');

        $this->assertSame(0, ReservationService::expireOverdue(), 'Run kedua tidak boleh ada yang kedaluwarsa lagi.');

        $this->assertSame(
            1,
            Reservation::query()->where('book_id', $book->id)->where('status', ReservationStatus::EXPIRED)->count(),
            'Tetap tepat 1 EXPIRED setelah 2x run.'
        );
        $this->assertSame(
            1,
            Reservation::query()->where('book_id', $book->id)->where('status', ReservationStatus::AVAILABLE)->count(),
            'Tetap tepat 1 AVAILABLE baru tanpa dobel setelah 2x run.'
        );
    }

    public function test_hook_pengembalian_mempromosikan_antrean_dan_notifikasi(): void
    {
        $book = $this->buatBuku(1, 1);
        $peminjam = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $pengantre = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        $loan = $this->pinjamPenuh($peminjam, $book);
        $this->assertEquals(LoanStatus::BORROWED, $loan->status);

        $antrean = ReservationService::reserve($pengantre, $book->id, $pengantre->user);
        $this->assertEquals(ReservationStatus::WAITING, $antrean->status);

        ReturnService::process($loan->refresh(), $this->staff);

        $this->assertEquals(
            ReservationStatus::AVAILABLE,
            $antrean->refresh()->status,
            'Pengembalian harus mempromosikan reservasi pertama menjadi AVAILABLE.'
        );
        $this->assertTrue(
            DB::table('notifications')
                ->where('type', 'reservation.available')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $pengantre->user->id)
                ->exists(),
            'Siswa pengantre harus menerima notifikasi reservation.available.'
        );
    }

    public function test_perpanjangan_ditolak_bila_buku_direservasi_orang_lain(): void
    {
        $book = $this->buatBuku(1, 1);
        $peminjam = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $pengantre = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        $loan = $this->pinjamPenuh($peminjam, $book);

        ReservationService::reserve($pengantre, $book->id, $pengantre->user);

        try {
            LoanExtensionService::request($loan->refresh(), $peminjam->user, 'Butuh lebih lama.');
            $this->fail('Perpanjangan buku yang direservasi siswa lain harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_cancel_milik_sendiri_boleh_milik_orang_lain_ditolak(): void
    {
        $book = $this->buatBuku(0, 0);
        $lain = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        $milikku = ReservationService::reserve($this->student, $book->id, $this->student->user);

        $dibatalkan = ReservationService::cancel($milikku, $this->student->user);
        $this->assertEquals(ReservationStatus::CANCELLED, $dibatalkan->status, 'Pemilik boleh membatalkan miliknya.');

        $milikOrang = ReservationService::reserve($lain, $book->id, $lain->user);

        try {
            ReservationService::cancel($milikOrang, $this->student->user);
            $this->fail('Siswa lain membatalkan milik orang harus melempar LoanException.');
        } catch (LoanException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertTrue($milikOrang->refresh()->isActive(), 'Reservasi orang lain harus tetap aktif.');
    }

    public function test_command_expire_berhasil_tanpa_merusak_data(): void
    {
        $book = $this->buatBuku(1, 1);
        $peminjam = Student::factory()->create(['status' => StudentStatus::ACTIVE]);

        $loan = $this->pinjamPenuh($peminjam, $book);
        $loanCount = Loan::count();

        $pertama = ReservationService::reserve($this->student, $book->id, $this->student->user);
        ReservationService::reserve(
            Student::factory()->create(['status' => StudentStatus::ACTIVE]),
            $book->id
        );

        ReservationService::promoteNext($book, $this->staff);
        $pertama->refresh()->update(['expires_at' => now()->subDay()]);

        $stokSebelum = $book->refresh()->available_stock;

        $this->artisan('reservations:expire')
            ->expectsOutputToContain('1 reservasi')
            ->assertExitCode(0);

        $this->assertEquals(ReservationStatus::EXPIRED, $pertama->refresh()->status);
        $this->assertSame($loanCount, Loan::count(), 'Jumlah loan tidak boleh berubah.');
        $this->assertTrue(Book::query()->whereKey($book->id)->exists(), 'Buku tidak boleh hilang.');
        $this->assertSame($stokSebelum, $book->refresh()->available_stock, 'Stok buku tidak boleh berubah oleh expire.');
    }

    public function test_expireOverdue_menerima_batas_waktu_eksplisit(): void
    {
        $book = $this->buatBuku(0, 0);

        $reservation = ReservationService::reserve($this->student, $book->id, $this->student->user);
        ReservationService::promoteNext($book, $this->staff);
        $reservation->refresh()->update(['expires_at' => Carbon::now()->subHour()]);

        $this->assertSame(1, ReservationService::expireOverdue(Carbon::now()));
        $this->assertEquals(ReservationStatus::EXPIRED, $reservation->refresh()->status);
    }
}
