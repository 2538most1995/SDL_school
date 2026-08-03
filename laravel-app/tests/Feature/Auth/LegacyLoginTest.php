<?php

namespace Tests\Feature\Auth;

use App\Contracts\LegacyIdentityProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacyLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('legacy.enabled', true);
        $this->app->instance(LegacyIdentityProvider::class, $this->identityProvider());
    }

    public function test_staff_login_creates_a_local_shadow_identity(): void
    {
        $this->postJson('/auth/login', [
            'identifier' => 'real.teacher',
            'password' => 'correct-password',
            'login_type' => 'staff',
        ])->assertOk()
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.district_id', 1)
            ->assertJsonPath('data.auth_source', 'legacy')
            ->assertJsonPath('data.districts.0.id', 1)
            ->assertJsonPath('data.districts.0.code', 'test-district');

        $user = User::query()->where('legacy_key', 'staff:42')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('correct-password', $user->password);
    }

    public function test_student_login_detects_the_school_without_district_input(): void
    {
        $this->postJson('/auth/login', [
            'identifier' => '1234567890123',
            'password' => 'STUDENT-001',
            'login_type' => 'student',
        ])->assertOk()
            ->assertJsonPath('data.username', 'STUDENT-001')
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.district_id', 2)
            ->assertJsonPath('data.districts.0.id', 2)
            ->assertJsonPath('data.districts.0.code', 'future-school');

        $this->assertDatabaseHas('users', [
            'legacy_key' => 'student:2:3:STUDENT-001',
            'student_code' => 'STUDENT-001',
            'auth_source' => 'legacy',
        ]);
    }

    public function test_invalid_legacy_credentials_fail_without_creating_a_shadow_user(): void
    {
        $this->postJson('/auth/login', [
            'identifier' => 'real.teacher',
            'password' => 'wrong',
            'login_type' => 'staff',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');

        $this->assertDatabaseCount('users', 0);
    }

    private function identityProvider(): LegacyIdentityProvider
    {
        return new class implements LegacyIdentityProvider
        {
            public function districts(): array
            {
                return [
                    ['id' => 1, 'name' => 'อำเภอทดสอบ', 'code' => 'test-district', 'is_active' => true],
                    ['id' => 2, 'name' => 'สถานศึกษาใหม่', 'code' => 'future-school', 'is_active' => true],
                ];
            }

            public function authenticateStaff(string $username, string $password): ?array
            {
                if ($username !== 'real.teacher' || $password !== 'correct-password') {
                    return null;
                }

                return [
                    'legacy_key' => 'staff:42',
                    'legacy_user_id' => 42,
                    'username' => 'real.teacher',
                    'display_username' => 'real.teacher',
                    'student_code' => null,
                    'name' => 'ครูทดสอบ',
                    'role' => 'teacher',
                    'district_id' => 1,
                    'assigned_groups' => ['G-01'],
                    'auth_source' => 'legacy',
                ];
            }

            public function authenticateStudent(string $citizenId, string $studentCode): ?array
            {
                if ($citizenId !== '1234567890123' || $studentCode !== 'STUDENT-001') {
                    return null;
                }

                return [
                    'legacy_key' => 'student:2:3:STUDENT-001',
                    'legacy_user_id' => null,
                    'username' => 'student:2:3:STUDENT-001',
                    'display_username' => 'STUDENT-001',
                    'student_code' => 'STUDENT-001',
                    'name' => 'นักศึกษาทดสอบ',
                    'role' => 'student',
                    'district_id' => 2,
                    'assigned_groups' => [],
                    'auth_source' => 'legacy',
                    'education_level' => 3,
                ];
            }
        };
    }
}
