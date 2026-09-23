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

    public function test_group_subdistrict_resolution_and_export_document(): void
    {
        $this->assertSame('เจ้าเสด็จ', \App\Domain\Students\Support\CurriculumCatalog::resolveGroupSubdistrict('กลุ่ม ศกร.ระดับตำบลเจ้าเสด็จ'));
        $this->assertSame('เสนา', \App\Domain\Students\Support\CurriculumCatalog::resolveGroupSubdistrict('ศกร.ระดับตำบลเสนา'));
        $this->assertSame('บ้านแพน', \App\Domain\Students\Support\CurriculumCatalog::resolveGroupSubdistrict('กศน.ตำบลบ้านแพน'));

        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        $teacher->name = 'คุณครู ทดสอบ';
        $teacher->save();
        Sanctum::actingAs($teacher);

        $payload = [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [
                ['code' => 'ทร11001', 'name' => 'ทักษะการเรียนรู้', 'credits' => 5, 'registered' => true, 'transferred' => false, 'remark' => ''],
            ],
            'elective_subjects' => [
                ['code' => 'พว12010', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 1', 'credits' => 2, 'registered' => true, 'transferred' => false, 'remark' => ''],
            ],
            'student_info' => [
                'name' => 'นายสมใจ เรียนดี',
                'teacher_name' => 'คุณครู ประจำกลุ่ม',
                'facebook' => '',
                'line_id' => '',
                'group' => 'กลุ่ม ศกร.ระดับตำบลเจ้าเสด็จ',
                'box_subdistrict' => 'เจ้าเสด็จ',
            ],
        ];

        $postRes = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);
        $postRes->assertOk()
            ->assertJsonPath('data.student_info.teacher_name', 'คุณครู ประจำกลุ่ม');

        // Test export HTML preview contains teacher name, student name, and resolved box subdistrict
        $htmlRes = $this->actingAs($teacher)->get('/learning/registration/view?scope=student&student=6650100001&term=1/2569');
        $htmlRes->assertOk()
            ->assertSee('นายสมใจ เรียนดี')
            ->assertSee('คุณครู ประจำกลุ่ม')
            ->assertSee('เจ้าเสด็จ');
    }

    public function test_future_terms_are_available_and_can_be_registered(): void
    {
        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        // Fetch student registration detail
        $res = $this->getJson('/api/v1/learning/registration/student/6650100001');
        $res->assertOk();

        $availableTerms = $res->json('data.available_terms');
        $this->assertIsArray($availableTerms);
        // Should contain strictly the single upcoming term 2/2569, but NOT far future terms like 1/2570
        $this->assertContains('2/2569', $availableTerms);
        $this->assertNotContains('1/2570', $availableTerms);

        // Register for future term 2/2569
        $payload = [
            'academic_term' => '2/2569',
            'compulsory_subjects' => [
                ['code' => 'พท11001', 'name' => 'ภาษาไทย', 'credits' => 3, 'registered' => true, 'transferred' => false, 'remark' => 'ลงเรียนเทอมหน้า'],
            ],
            'elective_subjects' => [],
            'notes' => 'แผนการลงทะเบียนล่วงหน้า ภาคเรียนที่ 2/2569',
        ];

        $saveRes = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);
        $saveRes->assertOk()
            ->assertJsonPath('data.academic_term', '2/2569')
            ->assertJsonPath('data.is_saved', true);

        // Verify retrieval for term 2/2569
        $getFutureRes = $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569');
        $getFutureRes->assertOk()
            ->assertJsonPath('data.academic_term', '2/2569')
            ->assertJsonPath('data.is_saved', true)
            ->assertJsonPath('data.notes', 'แผนการลงทะเบียนล่วงหน้า ภาคเรียนที่ 2/2569');
    }

    public function test_teacher_prefix_is_ensured_and_signature_font_size_is_16pt(): void
    {
        $teacher = $this->viewer('teacher', ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        // Save with teacher name missing prefix: "สุธาทิพย์ ดีจุ่น"
        $payload = [
            'academic_term' => '2/2569',
            'compulsory_subjects' => [],
            'elective_subjects' => [],
            'student_info' => [
                'name' => 'นางสาวบุญทิชา เกตุนุช',
                'teacher_name' => 'สุธาทิพย์ ดีจุ่น',
            ],
        ];

        $res = $this->postJson('/api/v1/learning/registration/student/6650100001', $payload);
        $res->assertOk()
            ->assertJsonPath('data.student_info.teacher_name', 'นางสาวสุธาทิพย์ ดีจุ่น');

        // Check HTML print view renders font-size: 16pt and proper prefix
        $htmlRes = $this->actingAs($teacher)->get('/learning/registration/view?scope=student&student=6650100001&term=2/2569');
        $htmlRes->assertOk()
            ->assertSee('นางสาวสุธาทิพย์ ดีจุ่น')
            ->assertSee('นางสาวบุญทิชา เกตุนุช')
            ->assertSee('font-size: 16pt;');

        // Check PDF download generation returns 200 with valid application/pdf
        $signedUrlRes = $this->getJson('/api/v1/learning/registration/signed-url?scope=student&student=6650100001&term=2/2569');
        $signedUrlRes->assertOk();
        $pdfUrl = $signedUrlRes->json('data.url');
        $this->assertNotEmpty($pdfUrl);

        $pdfRes = $this->get($pdfUrl);
        $pdfRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_course_status_recommendation_and_duplicate_warning(): void
    {
        $admin = $this->viewer('admin');
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/learning/registration/student/6650100001');
        $res->assertOk();

        $compulsory = $res->json('data.compulsory_subjects');
        $this->assertNotEmpty($compulsory);

        // Each compulsory subject must have course_status structure
        foreach ($compulsory as $subject) {
            $this->assertArrayHasKey('course_status', $subject);
            $st = $subject['course_status'];
            $this->assertArrayHasKey('status', $st);
            $this->assertArrayHasKey('status_label', $st);
            $this->assertArrayHasKey('status_badge', $st);
            $this->assertArrayHasKey('status_color', $st);
            $this->assertContains($st['status'], ['passed', 'transferred', 'pending_grade', 'failed', 'absent_exam', 'not_taken']);
        }

        // Common electives also have course_status
        $commonElectives = $res->json('data.common_electives');
        $this->assertNotEmpty($commonElectives);
        foreach ($commonElectives as $ce) {
            $this->assertArrayHasKey('course_status', $ce);
            $this->assertContains($ce['course_status']['status'], ['passed', 'transferred', 'pending_grade', 'failed', 'absent_exam', 'not_taken']);
        }
    }

    public function test_course_status_identifies_grade_kh_as_absent_exam_with_orange_color(): void
    {
        $admin = $this->viewer('admin');
        Sanctum::actingAs($admin);

        // Bind custom repository providing a grade 'ข' (absent) for ทร11001
        $demoRepo = $this->app->make(\App\Domain\Students\Repositories\DemoStudentRepository::class);
        $customRepo = new class($demoRepo) implements \App\Domain\Students\Repositories\StudentRepository {
            public function __construct(private readonly \App\Domain\Students\Repositories\DemoStudentRepository $inner) {}
            public function students(?array $districtIds = null): array { return $this->inner->students($districtIds); }
            public function find(string $code, ?int $districtId = null, ?int $level = null): ?\App\Domain\Students\Models\Student {
                return $this->inner->find($code, $districtId, $level);
            }
            public function gradesFor(\App\Domain\Students\Models\Student $student): array {
                $grades = $this->inner->gradesFor($student);
                $grades[] = new \App\Domain\Students\Models\Grade(
                    studentCode: $student->code,
                    subjectCode: 'ทร11001',
                    subjectName: 'ทักษะการเรียนรู้',
                    credits: 5.0,
                    subjectType: 'compulsory',
                    term: '1/2568',
                    grade: 'ข',
                    transferred: false,
                    examAttended: false,
                );
                return $grades;
            }
            public function gradesForMany(array $students): array { return $this->inner->gradesForMany($students); }
            public function subjectsFor(\App\Domain\Students\Models\Student $student): array { return $this->inner->subjectsFor($student); }
            public function kpchFor(\App\Domain\Students\Models\Student $student): array { return $this->inner->kpchFor($student); }
            public function moralFor(\App\Domain\Students\Models\Student $student): array { return $this->inner->moralFor($student); }
        };

        $this->app->instance(\App\Domain\Students\Repositories\StudentRepository::class, $customRepo);

        $res = $this->getJson('/api/v1/learning/registration/student/6650100001');
        $res->assertOk();

        $compulsory = $res->json('data.compulsory_subjects');
        $foundKh = null;
        foreach ($compulsory as $sub) {
            if ($sub['code'] === 'ทร11001') {
                $foundKh = $sub['course_status'];
                break;
            }
        }

        $this->assertNotNull($foundKh);
        $this->assertSame('absent_exam', $foundKh['status']);
        $this->assertSame('orange', $foundKh['status_color']);
        $this->assertSame('เกรด "ข" ขาดสอบ (ลงเรียนใหม่ได้)', $foundKh['status_badge']);
        $this->assertStringContainsString('ขาดสอบ', $foundKh['status_label']);
        $this->assertStringContainsString('แนะนำให้ลงทะเบียนเรียนใหม่', $foundKh['warning']);
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
