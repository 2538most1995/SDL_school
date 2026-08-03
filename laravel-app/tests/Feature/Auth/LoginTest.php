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
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.districts.0.id', $district->id)
            ->assertJsonPath('data.districts.0.code', 'sena')
            ->assertJsonStructure(['data' => ['assigned_groups', 'auth_source', 'districts']]);

        $this->assertAuthenticatedAs($user);
        $this->postJson('/auth/logout')->assertOk();
        $this->assertGuest();
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
