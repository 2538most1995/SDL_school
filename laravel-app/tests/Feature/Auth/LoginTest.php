<?php

namespace Tests\Feature\Auth;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_detects_the_application_subdirectory_from_the_request(): void
    {
        config()->set('app.url', 'https://school.example.test');

        $this->withServerVariables([
            'SCRIPT_NAME' => '/SDL_school/index.php',
            'SCRIPT_FILENAME' => base_path('../index.php'),
        ])->get('/SDL_school/login')
            ->assertOk()
            ->assertSee('<meta name="app-base-path" content="/SDL_school">', false);
    }

    public function test_enabled_user_can_log_in_and_log_out(): void
    {
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        $user = User::factory()->create([
            'username' => 'student.test',
            'role' => 'student',
            'district_id' => $district->id,
            'password' => Hash::make('Correct123!'),
        ]);

        $this->postJson('/auth/login', [
            'identifier' => 'student.test',
            'password' => 'Correct123!',
            'login_type' => 'student',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.districts.0.id', $district->id)
            ->assertJsonPath('data.districts.0.code', 'sena')
            ->assertJsonStructure(['data' => ['assigned_groups', 'auth_source', 'districts']]);

        $this->assertAuthenticatedAs($user);
        $this->postJson('/auth/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_public_branding_exposes_the_login_mode_needed_by_the_student_form(): void
    {
        District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);

        config(['system_data.enabled' => false]);
        $this->getJson('/api/v1/auth/branding')
            ->assertOk()
            ->assertJsonPath('data.loginMode', 'local');

        config(['system_data.enabled' => true]);
        $this->getJson('/api/v1/auth/branding')
            ->assertOk()
            ->assertJsonPath('data.loginMode', 'local');
    }

    public function test_login_uses_generic_error_for_wrong_credentials(): void
    {
        User::factory()->create(['username' => 'student.test']);

        $this->postJson('/auth/login', [
            'identifier' => 'student.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        User::factory()->create([
            'username' => 'disabled.test',
            'disabled_at' => now(),
            'password' => Hash::make('Correct123!'),
        ]);

        $this->postJson('/auth/login', [
            'identifier' => 'disabled.test',
            'password' => 'Correct123!',
        ])->assertUnprocessable();
    }
}
