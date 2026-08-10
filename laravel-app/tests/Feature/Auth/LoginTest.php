<?php

namespace Tests\Feature\Auth;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
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
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);

        config(['system_data.student_enabled' => false]);
        $this->getJson('/api/v1/auth/branding')
            ->assertOk()
            ->assertJsonPath('data.districtId', $district->id)
            ->assertJsonPath('data.loginMode', 'local');

        config(['system_data.student_enabled' => true]);
        $this->getJson('/api/v1/auth/branding')
            ->assertOk()
            ->assertJsonPath('data.loginMode', 'student_credentials');
    }

    public function test_student_can_log_in_with_citizen_id_and_student_code_from_system_database(): void
    {
        config(['system_data.student_enabled' => true]);
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        $student = $this->student($district);
        $this->useStudents($student);

        $this->postJson('/auth/login', [
            'identifier' => '1101700203451',
            'password' => '1234567890',
            'login_type' => 'student',
            'district_id' => $district->id,
        ])->assertOk()
            ->assertJsonPath('data.username', '1234567890')
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.district_id', $district->id)
            ->assertJsonPath('data.assigned_groups.0', '230016')
            ->assertJsonPath('data.auth_source', 'system_import');

        $user = User::query()->where('student_code', '1234567890')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('นักศึกษา ทดสอบ', $user->name);
        $this->assertStringStartsWith('student-'.$district->id.'-', $user->username);
        $this->assertNotContains('1101700203451', array_map('strval', $user->getAttributes()));
    }

    public function test_student_login_rejects_wrong_or_cross_district_credentials(): void
    {
        config(['system_data.student_enabled' => true]);
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        $other = District::create(['name' => 'อำเภออื่น', 'code' => 'other']);
        $this->useStudents($this->student($district));

        $this->postJson('/auth/login', [
            'identifier' => '1101700203452',
            'password' => '1234567890',
            'login_type' => 'student',
            'district_id' => $district->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');

        $this->postJson('/auth/login', [
            'identifier' => '1101700203451',
            'password' => '1234567890',
            'login_type' => 'student',
            'district_id' => $other->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabled_imported_student_account_cannot_log_in(): void
    {
        config(['system_data.student_enabled' => true]);
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        $this->useStudents($this->student($district));
        User::factory()->create([
            'username' => 'student-disabled',
            'role' => 'student',
            'district_id' => $district->id,
            'student_code' => '1234567890',
            'disabled_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'identifier' => '1101700203451',
            'password' => '1234567890',
            'login_type' => 'student',
            'district_id' => $district->id,
        ])->assertUnprocessable();

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

    private function student(District $district): Student
    {
        return new Student(
            code: '1234567890',
            districtId: $district->id,
            districtName: $district->name,
            prefix: '',
            firstName: 'นักศึกษา',
            lastName: 'ทดสอบ',
            level: 3,
            levelLabel: 'มัธยมศึกษาตอนปลาย',
            groupCode: '230016',
            groupName: 'ศกร.ระดับตำบลบ้านแถว',
            enrollmentTerm: '1/2568',
            currentTerm: '1/2569',
            status: 'active',
            statusLabel: 'กำลังศึกษา',
            gpax: 2.75,
            creditsEarned: 42,
            creditsRequired: 76,
            kpchHours: 120,
            moralResult: 'ผ่าน',
            citizenId: '1101700203451',
        );
    }

    private function useStudents(Student ...$students): void
    {
        $this->app->instance(StudentRepository::class, new class($students) implements StudentRepository
        {
            /** @param list<Student> $students */
            public function __construct(private readonly array $students) {}

            public function students(?array $districtIds = null): array
            {
                return array_values(array_filter(
                    $this->students,
                    static fn (Student $student): bool => $districtIds === null || in_array($student->districtId, $districtIds, true),
                ));
            }

            public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
            {
                $matches = array_values(array_filter(
                    $this->students,
                    static fn (Student $student): bool => $student->code === trim($code)
                        && ($districtId === null || $student->districtId === $districtId)
                        && ($level === null || $student->level === $level),
                ));

                return count($matches) === 1 ? $matches[0] : null;
            }

            public function gradesFor(Student $student): array
            {
                return [];
            }

            public function gradesForMany(array $students): array
            {
                return [];
            }

            public function subjectsFor(Student $student): array
            {
                return [];
            }

            public function kpchFor(Student $student): array
            {
                return [];
            }

            public function moralFor(Student $student): array
            {
                return [];
            }
        });
    }
}
