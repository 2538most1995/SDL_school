<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
    }

    public function test_admin_can_access_registration_workspace(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->getJson('/api/v1/learning/registration/workspace');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'term',
                    'terms',
                    'groups',
                    'total_students',
                    'items',
                ],
            ]);
    }

    public function test_teacher_workspace_is_scoped_to_assigned_groups(): void
    {
        // 6650100001 is in group SENA-P1-A
        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/v1/learning/registration/workspace');

        $response->assertOk();
        $students = $response->json('data.items');
        $this->assertNotEmpty($students);
        foreach ($students as $item) {
            $this->assertSame('SENA-P1-A', $item['group_code']);
        }
    }

    public function test_user_can_get_student_registration_details_with_standard_subjects(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->getJson('/api/v1/learning/registration/student/6650100001');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'student' => ['code', 'name', 'level', 'level_label', 'group_code'],
                    'academic_term',
                    'requirements' => ['compulsory_required', 'compulsory_earned', 'elective_required'],
                    'compulsory_subjects',
                    'elective_subjects',
                    'common_electives',
                    'is_saved',
                ],
            ]);

        // Compulsory subjects should contain standard NFE subjects
        $compulsory = $response->json('data.compulsory_subjects');
        $this->assertCount(14, $compulsory);
    }

    public function test_teacher_can_save_course_registration_for_assigned_student(): void
    {
        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        $payload = [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [
                ['code' => 'ทร11001', 'name' => 'ทักษะการเรียนรู้', 'credits' => 5, 'registered' => true, 'transferred' => false, 'remark' => ''],
                ['code' => 'พท11001', 'name' => 'ภาษาไทย', 'credits' => 3, 'registered' => true, 'transferred' => false, 'remark' => ''],
            ],
            'elective_subjects' => [
                ['code' => 'พว12010', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 1', 'credits' => 2, 'registered' => true, 'transferred' => false, 'remark' => ''],
            ],
            'notes' => 'ลงทะเบียนครบตามแผนการเรียน',
        ];

        $response = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);

        $response->assertOk()
            ->assertJsonPath('data.is_saved', true)
            ->assertJsonPath('data.academic_term', '1/2569');

        $this->assertDatabaseHas('learning_course_registrations', [
            'district_id' => $this->district->id,
            'student_code' => '6650100001',
            'academic_term' => '1/2569',
            'notes' => 'ลงทะเบียนครบตามแผนการเรียน',
        ]);
    }

    public function test_teacher_cannot_save_registration_for_student_outside_assigned_group(): void
    {
        // Teacher assigned only to SENA-P1-B, but 6650100001 is in SENA-P1-A
        $teacher = $this->viewer('teacher', ['SENA-P1-B']);
        Sanctum::actingAs($teacher);

        $payload = [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [],
            'elective_subjects' => [],
        ];

        $response = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);

        $response->assertNotFound();
    }

    public function test_teacher_can_save_and_retrieve_custom_student_info(): void
    {
        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        $payload = [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [],
            'elective_subjects' => [],
            'student_info' => [
                'name' => 'นายทดสอบ สมมุติ',
                'citizen_id' => '1234567890123',
                'code' => '6650100001',
                'phone' => '0891234567',
                'facebook' => 'Test FB',
                'line_id' => 'testline',
                'house_no' => '99/9',
                'moo' => '1',
                'subdistrict' => 'ดอนลาน',
                'district' => 'เสนา',
                'province' => 'พระนครศรีอยุธยา',
                'group' => 'ศกร.ระดับตำบลเจ้าเสด็จ',
                'box_subdistrict' => 'ดอนลาน',
                'compulsory_earned' => 20,
                'elective_earned' => 10,
                'compulsory_remaining' => 16,
                'elective_remaining' => 2,
                'term_no' => '1',
                'term_year' => '2569',
            ],
            'notes' => 'แก้ไขข้อมูลส่วนตัวสำเร็จ',
        ];

        $response = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);

        $response->assertOk()
            ->assertJsonPath('data.student_info.name', 'นายทดสอบ สมมุติ')
            ->assertJsonPath('data.student_info.phone', '0891234567')
            ->assertJsonPath('data.student_info.group', 'ศกร.ระดับตำบลเจ้าเสด็จ');

        // Check get endpoint returns saved student_info
        $getRes = $this->getJson('/api/v1/learning/registration/student/6650100001?term=1/2569');
        $getRes->assertOk()
            ->assertJsonPath('data.student_info.name', 'นายทดสอบ สมมุติ')
            ->assertJsonPath('data.student_info.house_no', '99/9');
    }

    /** @param list<string> $groups */
    private function viewer(string $role, array $groups = [], ?string $username = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'district_id' => $this->district->id,
            'assigned_groups' => $groups,
            'username' => $username,
            'student_code' => $role === 'student' ? $username : null,
        ]);
    }
}
