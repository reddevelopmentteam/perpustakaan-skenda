<?php

namespace Tests\Feature\Dashboard;

use App\Enums\FineStatus;
use App\Enums\LoanStatus;
use App\Enums\ReservationStatus;
use App\Filament\Widgets\ActiveStudentsTable;
use App\Filament\Widgets\CategoryChart;
use App\Filament\Widgets\LoansChart;
use App\Filament\Widgets\PopularBooksTable;
use App\Filament\Widgets\StaffOverviewStats;
use App\Filament\Widgets\StudentOverviewStats;
use App\Models\Book;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function invokeProtected(object $object, string $method): array
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object);
    }

    private function makeStudentUser(string $nis = '2526001'): array
    {
        $user = User::factory()->student()->create();
        $student = Student::factory()->create(['user_id' => $user->id, 'student_number' => $nis]);

        return [$user, $student];
    }

    private function makeLoan(Student $student, array $overrides = []): Loan
    {
        $book = Book::factory()->create();

        $loan = Loan::create(array_merge([
            'loan_number' => 'PJ-'.uniqid(),
            'student_id' => $student->id,
            'status' => LoanStatus::BORROWED,
            'requested_at' => now()->subDays(5),
            'borrowed_at' => now()->subDays(4),
            'due_at' => now()->addDays(3),
        ], $overrides));

        LoanItem::create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 1,
        ]);

        return $loan;
    }

    public function test_siswa_melihat_stat_miliknya_saja(): void
    {
        [$userA, $studentA] = $this->makeStudentUser('2526001');
        [$userB] = $this->makeStudentUser('2526002');

        $this->makeLoan($studentA);
        $this->makeLoan($studentA, ['loan_number' => 'PJ-'.uniqid().'-2', 'status' => LoanStatus::OVERDUE, 'due_at' => now()->subDay()]);

        $studentB = Student::where('student_number', '2526002')->firstOrFail();
        $this->makeLoan($studentB, ['loan_number' => 'PJ-'.uniqid().'-3']);

        Fine::create([
            'loan_id' => $studentA->loans()->firstOrFail()->id,
            'student_id' => $studentA->id,
            'amount' => 5000,
            'late_days' => 5,
            'fine_rate_snapshot' => 1000,
            'status' => FineStatus::UNPAID,
        ]);

        $this->actingAs($userA);

        $this->assertTrue(StudentOverviewStats::canView());
        $this->assertFalse(StaffOverviewStats::canView());

        $html = Livewire::test(StudentOverviewStats::class)->html();

        $this->assertStringContainsString('Sedang Dipinjam', $html);
        $this->assertStringContainsString('Rp 5.000', $html);
        $this->assertStringNotContainsString('Ringkasan Perpustakaan', $html);

        $this->actingAs($userB);
        $this->assertTrue(StudentOverviewStats::canView());
        $htmlB = Livewire::test(StudentOverviewStats::class)->html();
        $this->assertStringNotContainsString('Rp 5.000', $htmlB);
    }

    public function test_staff_melihat_global_dan_siswa_tidak(): void
    {
        $staff = User::factory()->staff()->create();
        [$studentUser, $student] = $this->makeStudentUser();

        $this->makeLoan($student);

        $this->actingAs($staff);
        $this->assertTrue(StaffOverviewStats::canView());
        $this->assertTrue(LoansChart::canView());
        $this->assertTrue(PopularBooksTable::canView());
        $this->assertTrue(ActiveStudentsTable::canView());
        $this->assertFalse(StudentOverviewStats::canView());

        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super);
        $this->assertTrue(StaffOverviewStats::canView());

        $this->actingAs($studentUser);
        $this->assertFalse(StaffOverviewStats::canView());
        $this->assertFalse(LoansChart::canView());
        $this->assertFalse(CategoryChart::canView());
        $this->assertTrue(StudentOverviewStats::canView());
    }

    public function test_guest_widget_hidden(): void
    {
        auth()->logout();

        $this->assertFalse(StudentOverviewStats::canView());
        $this->assertFalse(StaffOverviewStats::canView());
        $this->assertFalse(LoansChart::canView());

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_chart_data_terisi(): void
    {
        $staff = User::factory()->staff()->create();
        [, $student] = $this->makeStudentUser();

        $book = Book::factory()->create();
        $loan = Loan::create([
            'loan_number' => 'PJ-CHART-1',
            'student_id' => $student->id,
            'status' => LoanStatus::RETURNED,
            'requested_at' => now()->subDays(2),
            'borrowed_at' => now()->subDays(2),
            'due_at' => now()->addDays(5),
            'returned_at' => now()->subDay(),
        ]);
        LoanItem::create(['loan_id' => $loan->id, 'book_id' => $book->id, 'quantity' => 1]);

        $this->actingAs($staff);

        $chart = Livewire::test(LoansChart::class)->instance();
        $loansData = $this->invokeProtected($chart, 'getData');
        $this->assertNotEmpty($loansData['labels'] ?? []);
        $this->assertCount(2, $loansData['datasets'] ?? []);
        $this->assertSame('Dipinjam', $loansData['datasets'][0]['label']);
        $this->assertContains(1, $loansData['datasets'][0]['data']);

        $category = Livewire::test(CategoryChart::class)->instance();
        $categoryData = $this->invokeProtected($category, 'getData');
        $this->assertNotEmpty($categoryData['labels'] ?? []);
    }

    public function test_tabel_actionable_dan_populer_terbatas(): void
    {
        $staff = User::factory()->staff()->create();
        [$studentUser, $student] = $this->makeStudentUser();

        $this->makeLoan($student, ['loan_number' => 'PJ-PENDING-1', 'status' => LoanStatus::PENDING, 'borrowed_at' => null, 'due_at' => null]);
        $this->makeLoan($student, ['loan_number' => 'PJ-OVERDUE-1', 'status' => LoanStatus::OVERDUE, 'due_at' => now()->subDays(2)]);

        Reservation::create([
            'reservation_number' => 'RSV-'.uniqid(),
            'student_id' => $student->id,
            'book_id' => Book::factory()->create()->id,
            'status' => ReservationStatus::WAITING,
            'reserved_at' => now(),
            'queue_position' => 1,
        ]);

        $this->actingAs($staff);

        $response = $this->get('/admin');
        $response->assertOk();

        $html = Livewire::test(\App\Filament\Widgets\ActionableLoansTable::class)->html();
        $this->assertStringContainsString('Peminjaman yang harus segera ditindaklanjuti', $html);
        $this->assertStringContainsString('PJ-PENDING-1', $html);
        $this->assertStringContainsString('PJ-OVERDUE-1', $html);

        // Siswa tetap dapat membuka dashboard (hanya widget miliknya yang tampil).
        $this->actingAs($studentUser);
        $this->get('/admin')->assertOk();
    }
}
