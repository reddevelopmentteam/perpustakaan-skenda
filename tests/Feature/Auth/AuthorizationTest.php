<?php

namespace Tests\Feature\Auth;

use App\Enums\LoanStatus;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $staff;

    private User $studentUser;

    private User $otherStudentUser;

    private User $inactiveUser;

    private Loan $ownLoan;

    private Loan $otherLoan;

    private Book $book;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->staff = User::factory()->staff()->create();
        $this->inactiveUser = User::factory()->inactive()->create();

        $studentA = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $this->studentUser = $studentA->user;
        $this->studentUser->role = UserRole::STUDENT;
        $this->studentUser->save();

        $studentB = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
        $this->otherStudentUser = $studentB->user;
        $this->otherStudentUser->role = UserRole::STUDENT;
        $this->otherStudentUser->save();

        $this->category = Category::factory()->create();
        $this->book = Book::factory()->create(['category_id' => $this->category->id]);
        BookCopy::factory()->create(['book_id' => $this->book->id]);
        Student::factory()->create(['status' => StudentStatus::BLOCKED]);
        $this->ownLoan = Loan::create([
            'loan_number' => 'PJ-20260101-0001',
            'student_id' => $studentA->id,
            'status' => LoanStatus::BORROWED,
        ]);
        $this->otherLoan = Loan::create([
            'loan_number' => 'PJ-20260101-0002',
            'student_id' => $studentB->id,
            'status' => LoanStatus::BORROWED,
        ]);
    }

    public function test_siswa_hanya_bisa_melihat_pinjaman_miliknya_sendiri(): void
    {
        $this->assertTrue($this->studentUser->can('view', $this->ownLoan));
        $this->assertFalse($this->studentUser->can('view', $this->otherLoan));

        $this->assertTrue($this->otherStudentUser->can('view', $this->otherLoan));
        $this->assertFalse($this->otherStudentUser->can('view', $this->ownLoan));
    }

    public function test_siswa_tidak_bisa_membuat_buku_atau_kategori(): void
    {
        $this->assertFalse($this->studentUser->can('create', Book::class));
        $this->assertFalse($this->studentUser->can('create', Category::class));
    }

    public function test_petugas_bisa_update_buku_tapi_tidak_bisa_update_super_admin_atau_dirinya_sendiri(): void
    {
        $this->assertTrue($this->staff->can('update', $this->book));

        // Petugas dilarang menyentuh akun super admin.
        $this->assertFalse($this->staff->can('update', $this->superAdmin));

        // Petugas dilarang mengubah akunnya sendiri (mencegah eskalasi role mandiri).
        $this->assertFalse($this->staff->can('update', $this->staff));

        // Petugas boleh mengelola akun siswa (non-eskalasi, enforcement level field di Resources).
        $studentAccount = User::factory()->student()->create();
        $this->assertTrue($this->staff->can('update', $studentAccount));
    }

    public function test_petugas_tidak_bisa_mengakses_setting_dan_audit_log(): void
    {
        Setting::set('loan_duration_days', '7');
        $setting = Setting::where('key', 'loan_duration_days')->firstOrFail();
        $auditLog = AuditLog::create([
            'user_id' => $this->staff->id,
            'action' => 'auth.login',
        ]);

        $this->assertFalse($this->staff->can('viewAny', Setting::class));
        $this->assertFalse($this->staff->can('view', $setting));
        $this->assertFalse($this->staff->can('update', $setting));

        $this->assertFalse($this->staff->can('viewAny', AuditLog::class));
        $this->assertFalse($this->staff->can('view', $auditLog));
    }

    public function test_super_admin_bisa_semua_aksi_kunci(): void
    {
        $this->assertTrue($this->superAdmin->can('create', User::class));
        $this->assertTrue($this->superAdmin->can('viewAny', Setting::class));
        $this->assertTrue($this->superAdmin->can('delete', $this->ownLoan));
        $this->assertTrue($this->superAdmin->can('update', $this->book));
        $this->assertTrue($this->superAdmin->can('view', $this->otherLoan));
    }

    public function test_guest_tidak_bisa_mengakses_panel_admin(): void
    {
        $this->assertGuest();

        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
