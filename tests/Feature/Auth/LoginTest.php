<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Filament\Auth\CustomLogin;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengguna_aktif_bisa_login_dengan_email(): void
    {
        $user = User::factory()->create([
            'email' => 'petugas@skenda.test',
            'role' => UserRole::STAFF,
            'is_active' => true,
        ]);

        $this->assertGuest();

        Livewire::test(CustomLogin::class)
            ->fillForm([
                'login' => 'petugas@skenda.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_pengguna_aktif_bisa_login_dengan_username(): void
    {
        $user = User::factory()->create([
            'email' => 'siswa-login@skenda.test',
            'username' => 'siswa_login_unik',
            'role' => UserRole::STUDENT,
            'is_active' => true,
        ]);

        $this->assertGuest();

        Livewire::test(CustomLogin::class)
            ->fillForm([
                'login' => 'siswa_login_unik',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_password_salah_menampilkan_error_dan_tidak_terautentikasi(): void
    {
        User::factory()->create([
            'email' => 'staff-salah@skenda.test',
            'role' => UserRole::STAFF,
            'is_active' => true,
        ]);

        Livewire::test(CustomLogin::class)
            ->fillForm([
                'login' => 'staff-salah@skenda.test',
                'password' => 'kata-sandi-salah',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);

        $this->assertGuest();
    }

    public function test_pengguna_nonaktif_tidak_bisa_login_walaupun_password_benar(): void
    {
        User::factory()->create([
            'email' => 'nonaktif@skenda.test',
            'username' => 'nonaktif_unik',
            'role' => UserRole::STAFF,
            'is_active' => false,
        ]);

        Livewire::test(CustomLogin::class)
            ->fillForm([
                'login' => 'nonaktif@skenda.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['login']);

        $this->assertGuest();
    }

    public function test_login_sukses_menulis_audit_tanpa_field_password(): void
    {
        $user = User::factory()->create([
            'email' => 'audit-login@skenda.test',
            'role' => UserRole::STAFF,
            'is_active' => true,
        ]);

        Livewire::test(CustomLogin::class)
            ->fillForm([
                'login' => 'audit-login@skenda.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();

        $log = AuditLog::query()
            ->where('action', 'auth.login')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);

        $serialized = json_encode([
            'old' => $log->old_values,
            'new' => $log->new_values,
            'ua' => $log->user_agent,
        ]);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsStringIgnoringCase('password', (string) $serialized);
    }

    public function test_halaman_login_dapat_diakses_guest_dan_setelah_login_akses_panel_sesuai_status_aktif(): void
    {
        $this->get('/admin/login')->assertOk();

        $superAdmin = User::factory()->superAdmin()->create(['is_active' => true]);
        $staff = User::factory()->staff()->create(['is_active' => true]);
        $student = User::factory()->student()->create(['is_active' => true]);
        $nonaktif = User::factory()->staff()->create(['is_active' => false]);

        $panel = Filament::getPanel('admin');
        $this->assertNotNull($panel);

        foreach ([$superAdmin, $staff, $student] as $user) {
            $this->assertTrue($user->canAccessPanel($panel));
            $this->actingAs($user)->get('/admin')->assertOk();
        }

        $this->assertFalse($nonaktif->canAccessPanel($panel));
        $this->actingAs($nonaktif)->get('/admin')->assertForbidden();
    }

}
