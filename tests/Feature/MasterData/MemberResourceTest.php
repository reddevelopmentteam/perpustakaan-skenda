<?php

namespace Tests\Feature\MasterData;

use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MemberResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dapat_melihat_daftar_anggota(): void
    {
        $staff = User::factory()->staff()->create();
        Student::factory()->create();

        $this->actingAs($staff);

        $this->get('/admin/students')->assertOk();
    }

    public function test_super_admin_dapat_melihat_daftar_pengguna(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        $this->get('/admin/users')->assertOk();
    }

    public function test_siswa_bisa_melihat_anggota_tetapi_tidak_bisa_membuat(): void
    {
        $student = Student::factory()->create();
        $studentUser = $student->user;
        $studentUser->role = UserRole::STUDENT;
        $studentUser->save();

        $this->actingAs($studentUser);

        $this->assertTrue(Gate::allows('viewAny', Student::class));
        $this->assertFalse(Gate::allows('create', Student::class));
    }

    public function test_staff_bisa_update_akun_siswa_tetapi_tidak_untuk_super_admin(): void
    {
        $staff = User::factory()->staff()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $studentUser = User::factory()->student()->create();

        $this->actingAs($staff);

        $this->assertTrue(Gate::allows('update', $studentUser));
        $this->assertFalse(Gate::allows('update', $superAdmin));
        $this->assertFalse(Gate::allows('update', $staff));
    }

    public function test_staff_tidak_bisa_mengubah_peran_atau_menonaktifkan_diri_sendiri(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $this->assertFalse(Gate::allows('update', $staff));

        $guarded = \App\Filament\Resources\Users\UserResource::guardRoleEscalation(
            ['role' => UserRole::SUPER_ADMIN->value, 'is_active' => false, 'name' => $staff->name],
            $staff->fresh(),
        );

        $this->assertArrayNotHasKey('role', $guarded);
        $this->assertArrayNotHasKey('is_active', $guarded);
    }


    public function test_nis_duplikat_ditolak_database(): void
    {
        Student::factory()->create([
            'user_id' => null,
            'student_number' => '2526001',
            'status' => StudentStatus::ACTIVE,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Student::factory()->create([
            'user_id' => null,
            'student_number' => '2526001',
            'status' => StudentStatus::ACTIVE,
        ]);
    }
}
