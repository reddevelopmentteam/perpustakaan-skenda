<?php

namespace Tests\Feature\Loans;

use App\Enums\LoanStatus;
use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\Student;
use App\Services\ReminderService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = Student::factory()->create(['status' => StudentStatus::ACTIVE]);
    }

    private function buatLoan(string $nomor, LoanStatus $status, Carbon $dueAt): Loan
    {
        return Loan::create([
            'loan_number' => $nomor,
            'student_id' => $this->student->id,
            'status' => $status,
            'borrowed_at' => Carbon::now()->subDay(),
            'due_at' => $dueAt,
        ]);
    }

    /**
     * @return list<array{row: object, data: array<string, mixed>}>
     */
    private function notifikasiUntuk(Loan $loan): array
    {
        $cocok = [];

        foreach (DB::table('notifications')->where('type', 'loan.reminder')->get() as $row) {
            $data = json_decode((string) $row->data, true);

            if (($data['loan_number'] ?? null) === $loan->loan_number) {
                $cocok[] = ['row' => $row, 'data' => $data];
            }
        }

        return $cocok;
    }

    public function test_reminder_days_parse_dan_fallback(): void
    {
        Setting::set('reminder_days', '3,1,0');
        $this->assertSame([3, 1, 0], ReminderService::reminderDays(), 'Parse standar harus [3,1,0].');

        Setting::set('reminder_days', '0, 3,1, 3');
        $this->assertSame([3, 1, 0], ReminderService::reminderDays(), 'Duplikat hilang, spasi ditoleransi, sort desc.');

        Setting::set('reminder_days', '');
        $this->assertSame([3, 1, 0], ReminderService::reminderDays(), 'String kosong harus fallback.');

        Setting::set('reminder_days', 'besok, -1, ??');
        $this->assertSame([3, 1, 0], ReminderService::reminderDays(), 'Nilai invalid harus fallback.');

        DB::table('settings')->where('key', 'reminder_days')->delete();
        Cache::forget('settings.reminder_days');
        $this->assertSame([3, 1, 0], ReminderService::reminderDays(), 'Key absen (belum di seeder) harus fallback.');
    }

    public function test_due_soon_h3_terkirim_ke_siswa(): void
    {
        Setting::set('reminder_days', '3,1,0');

        $due = Carbon::today()->addDays(3)->setTime(12, 0);
        $loan = $this->buatLoan('PJ-20250101-0001', LoanStatus::BORROWED, $due);

        $this->assertSame(1, ReminderService::sendDueReminders(), 'Satu pengingat H-3 harus terkirim.');

        $notif = $this->notifikasiUntuk($loan);
        $this->assertCount(1, $notif, 'Satu baris notifikasi untuk loan tersebut.');
        $this->assertSame('loan.reminder', $notif[0]['row']->type);
        $this->assertSame($this->student->user_id, (int) $notif[0]['row']->notifiable_id, 'Penerima adalah user siswa pemilik.');

        $data = $notif[0]['data'];
        $this->assertSame($loan->loan_number, $data['loan_number']);
        $this->assertSame('due_soon', $data['kind']);
        $this->assertSame(3, $data['days']);
        $this->assertStringContainsString($loan->loan_number, (string) $data['message'], 'Pesan memuat nomor peminjaman.');
        $this->assertStringContainsString($due->format('d/m/Y'), (string) $data['message'], 'Pesan memuat tanggal jatuh tempo.');
        $this->assertSame("/admin/loans/{$loan->getKey()}", $data['url']);

        $this->assertTrue(
            AuditLog::query()->where('action', 'loans.reminder_sent')
                ->where('auditable_type', Loan::class)
                ->where('auditable_id', $loan->id)->exists(),
            'Audit loans.reminder_sent wajib tertulis.'
        );
    }

    public function test_due_soon_idempotent_dua_kali_hari_sama(): void
    {
        Setting::set('reminder_days', '3,1,0');

        $loan = $this->buatLoan('PJ-20250101-0002', LoanStatus::BORROWED, Carbon::today()->addDays(3)->setTime(12, 0));

        $this->assertSame(1, ReminderService::sendDueReminders());
        $this->assertSame(0, ReminderService::sendDueReminders(), 'Panggilan kedua hari yang sama tidak mengirim lagi.');
        $this->assertCount(1, $this->notifikasiUntuk($loan), 'Total notifikasi tetap 1 (dedupe).');
    }

    public function test_due_tidak_cocok_h_tidak_terkirim(): void
    {
        Setting::set('reminder_days', '3,0');

        $loan = $this->buatLoan('PJ-20250101-0003', LoanStatus::BORROWED, Carbon::today()->addDays(1)->setTime(12, 0));

        $this->assertSame(0, ReminderService::sendDueReminders(), 'Jatuh tempo +1 hari tidak cocok H manapun.');
        $this->assertCount(0, $this->notifikasiUntuk($loan));
    }

    public function test_overdue_terkirim_idempotent_dan_command_sukses(): void
    {
        Setting::set('reminder_days', '3,1,0');

        $loan = $this->buatLoan('PJ-20250101-0004', LoanStatus::OVERDUE, Carbon::today()->subDays(2)->setTime(12, 0));

        $this->assertSame(1, ReminderService::sendOverdueReminders(), 'Satu pengingat keterlambatan harus terkirim.');

        $notif = $this->notifikasiUntuk($loan);
        $this->assertCount(1, $notif);
        $this->assertSame('overdue', $notif[0]['data']['kind']);
        $this->assertSame(2, $notif[0]['data']['days'], 'Keterlambatan 2 hari.');
        $this->assertStringContainsString('terlambat', (string) $notif[0]['data']['message']);
        $this->assertStringContainsString($loan->loan_number, (string) $notif[0]['data']['message']);

        $this->assertSame(0, ReminderService::sendOverdueReminders(), 'Run kedua hari yang sama tidak mengirim lagi.');
        $this->assertCount(1, $this->notifikasiUntuk($loan), 'Total notifikasi overdue tetap 1.');

        $this->assertTrue(
            AuditLog::query()->where('action', 'loans.reminder_sent')
                ->where('auditable_type', Loan::class)
                ->where('auditable_id', $loan->id)->exists(),
            'Audit loans.reminder_sent wajib tertulis untuk overdue.'
        );

        $this->artisan('loans:send-reminders')->assertExitCode(0);
    }

    public function test_audit_reminder_sent_memuat_kind_days_loan_number(): void
    {
        Setting::set('reminder_days', '3,1,0');

        $loan = $this->buatLoan('PJ-20250101-0005', LoanStatus::BORROWED, Carbon::today()->addDays(1)->setTime(12, 0));

        ReminderService::sendDueReminders();

        $audit = AuditLog::query()->where('action', 'loans.reminder_sent')
            ->where('auditable_type', Loan::class)
            ->where('auditable_id', $loan->id)->first();

        $this->assertNotNull($audit, 'Audit loans.reminder_sent wajib ada.');
        $this->assertSame('due_soon', $audit->new_values['kind'] ?? null);
        $this->assertSame(1, $audit->new_values['days'] ?? null);
        $this->assertSame($loan->loan_number, $audit->new_values['loan_number'] ?? null);
    }

}
