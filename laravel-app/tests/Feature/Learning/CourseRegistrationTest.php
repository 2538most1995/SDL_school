<?php

namespace Tests\Feature\Learning;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Services\CourseRegistrationService;
use App\Domain\Students\Support\CurriculumCatalog;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        config()->set('system_data.write_enabled', true);
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

    public function test_registration_group_options_are_unique_names_scoped_by_level_and_still_filter_rows(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->getJson('/api/v1/learning/registration/workspace?level=2')
            ->assertOk();

        $groups = $response->json('data.groups');
        $labels = array_column($groups, 'label');
        $this->assertSame($labels, array_values(array_unique($labels)));
        $this->assertNotEmpty($groups);
        foreach ($response->json('data.items') as $item) {
            $this->assertSame(2, $item['level']);
        }

        $selectedGroup = $groups[0]['value'];
        $filtered = $this->getJson('/api/v1/learning/registration/workspace?level=2&group='.urlencode($selectedGroup))
            ->assertOk()
            ->json('data.items');
        $this->assertNotEmpty($filtered);
        foreach ($filtered as $item) {
            $this->assertSame($selectedGroup, $item['group_name']);
        }
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

    public function test_workspace_recognizes_distinct_itw_registrations_only_in_selected_term(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $this->registrationGrades([
            new Grade('6650100001', 'พท11001', 'ภาษาไทย', 3, 'compulsory', '2/2569', null),
            new Grade('6650100001', 'พท11001', 'ภาษาไทย', 3, 'compulsory', '2569/2', '0'),
            new Grade('6650100001', 'พว12010', 'พลังงาน', 2, 'elective', '2/69', ''),
            new Grade('6650100001', 'สค12021', 'การเงิน', 2, 'elective', '1/2569', '3'),
            new Grade('6650100001', ' ', 'ไม่มีรหัสวิชา', 2, 'elective', '2/2569', null),
        ]);

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.registration.is_saved', false)
            ->assertJsonPath('data.items.0.registration.is_imported', true)
            ->assertJsonPath('data.items.0.registration.imported_count', 2)
            ->assertJsonPath('data.items.0.is_credit_complete', false);

        $this->getJson('/api/v1/learning/registration/workspace?term=1/2570&search=6650100001')
            ->assertOk()
            ->assertJsonPath('data.items.0.registration.is_saved', false)
            ->assertJsonPath('data.items.0.registration.is_imported', false)
            ->assertJsonPath('data.items.0.registration.imported_count', 0);

        $this->assertDatabaseCount('learning_course_registrations', 0);
    }

    public function test_workspace_keeps_saved_and_itw_registration_states_independent(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $this->registrationGrades([
            new Grade('6650100001', 'พท11001', 'ภาษาไทย', 3, 'compulsory', '2/2569', null),
        ]);
        DB::table('learning_course_registrations')->insert([
            'district_id' => $this->district->id,
            'student_code' => '6650100001',
            'academic_term' => '2/2569',
            'education_level' => 1,
            'compulsory_subjects' => '[]',
            'elective_subjects' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001')
            ->assertOk()
            ->assertJsonPath('data.items.0.registration.is_saved', true)
            ->assertJsonPath('data.items.0.registration.total_count', 0)
            ->assertJsonPath('data.items.0.registration.is_imported', true)
            ->assertJsonPath('data.items.0.registration.imported_count', 1);
    }

    public function test_workspace_marks_credit_completion_only_when_actual_selected_term_registrations_cover_both_groups(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $grades = [];
        foreach (CurriculumCatalog::compulsorySubjects(1) as $subject) {
            if ($subject['code'] !== 'พท11001') {
                $grades[] = new Grade('6650100001', $subject['code'], $subject['name'], $subject['credits'], 'compulsory', '1/2568', '3');
            }
        }
        $grades[] = new Grade('6650100001', 'เลือกเดิม', 'วิชาเลือกที่ผ่านแล้ว', 10, 'elective', '1/2568', '3');
        $grades[] = new Grade('6650100001', 'พท11001', 'ภาษาไทย', 3, 'compulsory', '2/2569', null);
        $grades[] = new Grade('6650100001', 'พท11001', 'ภาษาไทย', 3, 'compulsory', '1/2570', null);
        $grades[] = new Grade('6650100001', 'เลือกใหม่', 'วิชาเลือกใหม่', 2, 'elective', '2570/1', null);
        $grades[] = new Grade('6650100001', 'เลือกใหม่', 'วิชาเลือกใหม่', 2, 'elective', '1/2570', null);
        // Repeating an already-passed elective must not add earned credits again.
        $grades[] = new Grade('6650100001', 'เลือกเดิม', 'วิชาเลือกที่ผ่านแล้ว', 10, 'elective', '1/2570', null);
        $this->registrationGrades($grades);

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001')
            ->assertOk()
            ->assertJsonPath('data.items.0.is_potential_graduate', true)
            ->assertJsonPath('data.items.0.registration.is_credit_complete_after_registration', false);

        $assertComplete = function (): void {
            $this->getJson('/api/v1/learning/registration/workspace?term=1/2570&search=6650100001')
                ->assertOk()
                ->assertJsonPath('data.items.0.is_credit_complete', false)
                ->assertJsonPath('data.items.0.registration.is_credit_complete_after_registration', true)
                ->assertJsonPath('data.items.0.registration.credit_policy.projected_compulsory', 36)
                ->assertJsonPath('data.items.0.registration.credit_policy.projected_elective', 12);
        };
        $assertComplete();

        DB::table('learning_course_registrations')->insert([
            'district_id' => $this->district->id,
            'student_code' => '6650100001',
            'academic_term' => '1/2570',
            'education_level' => 1,
            'compulsory_subjects' => '[]',
            'elective_subjects' => json_encode([['code' => 'เลือกใหม่', 'credits' => 2, 'registered' => true]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Saving the same imported elective must not double-count its credits.
        $assertComplete();

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2570&search=6650100001')
            ->assertOk()
            ->assertJsonPath('data.items.0.registration.is_imported', false)
            ->assertJsonPath('data.items.0.registration.is_credit_complete_after_registration', false);
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
        $this->assertDatabaseHas('audit_logs', [
            'district_id' => $this->district->id,
            'user_id' => $teacher->id,
            'event' => 'learning.course_registration.saved',
        ]);
    }

    public function test_registration_rejects_credits_above_the_regular_term_limit(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [
                ['code' => 'ทร11001', 'name' => 'แก้ชื่อจาก client', 'credits' => 1, 'registered' => true, 'transferred' => false],
                ['code' => 'พท11001', 'name' => 'ภาษาไทย', 'credits' => 3, 'registered' => true, 'transferred' => false],
                ['code' => 'พต11001', 'name' => 'ภาษาอังกฤษพื้นฐาน', 'credits' => 3, 'registered' => true, 'transferred' => false],
                ['code' => 'พค11001', 'name' => 'คณิตศาสตร์', 'credits' => 3, 'registered' => true, 'transferred' => false],
            ],
            'elective_subjects' => [
                ['code' => 'พว12010', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 1', 'credits' => 2, 'registered' => true, 'transferred' => false],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('credits');
        $this->assertDatabaseMissing('learning_course_registrations', [
            'district_id' => $this->district->id,
            'student_code' => '6650100001',
            'academic_term' => '1/2569',
        ]);
    }

    public function test_registration_normalizes_term_and_rejects_duplicate_subject_codes(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '2569/1',
            'compulsory_subjects' => [
                ['code' => 'พท11001', 'credits' => 3, 'registered' => true, 'transferred' => false],
            ],
            'elective_subjects' => [],
        ])->assertOk();

        $this->assertDatabaseHas('learning_course_registrations', [
            'district_id' => $this->district->id,
            'student_code' => '6650100001',
            'academic_term' => '1/2569',
        ]);

        $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [
                ['code' => 'พท11001', 'credits' => 3, 'registered' => true, 'transferred' => false],
            ],
            'elective_subjects' => [
                ['code' => 'พท11001', 'credits' => 1, 'registered' => true, 'transferred' => false],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('elective_subjects');
    }

    public function test_registration_write_respects_system_write_flag(): void
    {
        config()->set('system_data.write_enabled', false);
        Sanctum::actingAs($this->viewer('admin'));

        $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '1/2569',
            'compulsory_subjects' => [],
            'elective_subjects' => [],
        ])->assertServiceUnavailable();
    }

    public function test_workspace_marks_students_who_can_complete_both_credit_groups(): void
    {
        $admin = $this->viewer('admin');
        Sanctum::actingAs($admin);

        $compulsory = array_map(
            static fn (array $subject): array => [
                ...$subject,
                'registered' => true,
                'transferred' => false,
                'remark' => '',
            ],
            CurriculumCatalog::compulsorySubjects(1),
        );
        $elective = array_map(
            static fn (int $index): array => [
                'code' => "เลือก{$index}",
                'name' => "วิชาเลือก {$index}",
                'credits' => 2,
                'registered' => true,
                'transferred' => false,
                'remark' => '',
            ],
            range(1, 6),
        );

        DB::table('learning_course_registrations')->insert([
            'district_id' => $this->district->id,
            'academic_term' => '1/2569',
            'student_code' => '6650100001',
            'education_level' => 1,
            'group_code' => 'SENA-P1-A',
            'student_info' => null,
            'compulsory_subjects' => json_encode($compulsory, JSON_UNESCAPED_UNICODE),
            'elective_subjects' => json_encode($elective, JSON_UNESCAPED_UNICODE),
            'notes' => null,
            'registered_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/learning/registration/workspace?term=1/2569&search=6650100001')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', '6650100001')
            ->assertJsonPath('data.items.0.is_credit_complete', false)
            ->assertJsonPath('data.items.0.is_potential_graduate', true)
            ->assertJsonPath('data.items.0.registration.is_credit_complete_after_registration', true)
            ->assertJsonPath('data.items.0.registration.credit_policy.applicable_limit', 17)
            ->assertJsonPath('data.items.0.registration.credit_policy.exceeds_limit', true);
    }

    public function test_registration_shows_all_distinct_passed_electives_and_flags_new_term_opportunity(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $demoRepo = $this->app->make(DemoStudentRepository::class);
        $grades = [];
        foreach (CurriculumCatalog::compulsorySubjects(1) as $subject) {
            if ($subject['code'] === 'พท11001') {
                continue;
            }
            $grades[] = new Grade('6650100001', $subject['code'], $subject['name'], $subject['credits'], 'compulsory', '1/2568', '2');
        }
        foreach (CurriculumCatalog::commonElectiveSubjects(1) as $index => $subject) {
            $grades[] = new Grade('6650100001', $subject['code'], $subject['name'], $subject['credits'], 'elective', '2/2568', '2', transferred: $index === 0);
        }
        $first = CurriculumCatalog::commonElectiveSubjects(1)[0];
        $grades[] = new Grade('6650100001', $first['code'], $first['name'], $first['credits'], 'elective', '1/2568', '2');

        $this->app->instance(StudentRepository::class, new class($demoRepo, $grades) implements StudentRepository
        {
            public function __construct(private DemoStudentRepository $inner, private array $grades) {}

            public function students(?array $districtIds = null): array
            {
                return $this->inner->students($districtIds);
            }

            public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
            {
                return $this->inner->find($code, $districtId, $level);
            }

            public function gradesFor(Student $student): array
            {
                return $student->code === '6650100001' ? $this->grades : $this->inner->gradesFor($student);
            }

            public function gradesForMany(array $students): array
            {
                $result = $this->inner->gradesForMany($students);
                foreach ($students as $student) {
                    if ($student->code === '6650100001') {
                        $result["{$student->districtId}|{$student->level}|{$student->code}"] = $this->grades;
                    }
                }

                return $result;
            }

            public function subjectsFor(Student $student): array
            {
                return $this->inner->subjectsFor($student);
            }

            public function kpchFor(Student $student): array
            {
                return $this->inner->kpchFor($student);
            }

            public function moralFor(Student $student): array
            {
                return $this->inner->moralFor($student);
            }
        });

        $detail = $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569');
        $detail->assertOk()
            ->assertJsonPath('data.requirements.compulsory_earned', 33)
            ->assertJsonPath('data.requirements.elective_earned', 10)
            ->assertJsonCount(5, 'data.completed_electives')
            ->assertJsonPath('data.credit_policy.is_potential_graduate', true)
            ->assertJsonPath('data.credit_policy.can_complete_with_new_registration', true)
            ->assertJsonPath('data.credit_policy.new_registration_plan.total_credits_needed', 5);
        $this->assertCount(0, $detail->json('data.elective_subjects'));

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001')
            ->assertOk()
            ->assertJsonPath('data.items.0.is_credit_complete', false)
            ->assertJsonPath('data.items.0.is_potential_graduate', true);

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001&graduation_status=potential')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001&graduation_status=complete')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_registration_flags_students_with_completed_credits_as_credit_complete(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $demoRepo = $this->app->make(DemoStudentRepository::class);
        $grades = [];
        // Complete all compulsory subjects for primary level (36 credits)
        foreach (CurriculumCatalog::compulsorySubjects(1) as $subject) {
            $grades[] = new Grade('6650100001', $subject['code'], $subject['name'], $subject['credits'], 'compulsory', '1/2568', '3');
        }
        // Complete electives exceeding required (13 credits, required is 12)
        $grades[] = new Grade('6650100001', 'ทร02001', 'วิชาเลือก 1', 3.0, 'elective', '1/2568', '3');
        $grades[] = new Grade('6650100001', 'ทร02002', 'วิชาเลือก 2', 3.0, 'elective', '1/2568', '3');
        $grades[] = new Grade('6650100001', 'ทร02003', 'วิชาเลือก 3', 3.0, 'elective', '2/2568', '3');
        $grades[] = new Grade('6650100001', 'ทร02004', 'วิชาเลือก 4', 4.0, 'elective', '2/2568', '3');

        $this->app->instance(StudentRepository::class, new class($demoRepo, $grades) implements StudentRepository
        {
            public function __construct(private DemoStudentRepository $inner, private array $grades) {}

            public function students(?array $districtIds = null): array
            {
                return $this->inner->students($districtIds);
            }

            public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
            {
                return $this->inner->find($code, $districtId, $level);
            }

            public function gradesFor(Student $student): array
            {
                return $student->code === '6650100001' ? $this->grades : $this->inner->gradesFor($student);
            }

            public function gradesForMany(array $students): array
            {
                $result = $this->inner->gradesForMany($students);
                foreach ($students as $student) {
                    if ($student->code === '6650100001') {
                        $result["{$student->districtId}|{$student->level}|{$student->code}"] = $this->grades;
                    }
                }

                return $result;
            }

            public function subjectsFor(Student $student): array
            {
                return $this->inner->subjectsFor($student);
            }

            public function kpchFor(Student $student): array
            {
                return $this->inner->kpchFor($student);
            }

            public function moralFor(Student $student): array
            {
                return $this->inner->moralFor($student);
            }
        });

        $detail = $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569');
        $detail->assertOk()
            ->assertJsonPath('data.requirements.compulsory_earned', 36)
            ->assertJsonPath('data.requirements.compulsory_required', 36)
            ->assertJsonPath('data.requirements.compulsory_remaining', 0)
            ->assertJsonPath('data.requirements.elective_earned', 13)
            ->assertJsonPath('data.requirements.elective_required', 12)
            ->assertJsonPath('data.requirements.elective_remaining', 0)
            ->assertJsonPath('data.credit_policy.is_credit_complete', true)
            ->assertJsonPath('data.credit_policy.is_potential_graduate', false)
            ->assertJsonPath('data.credit_policy.uses_final_term_limit', true)
            ->assertJsonPath('data.credit_policy.applicable_limit', 17);

        $workspace = $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001');
        $workspace->assertOk()
            ->assertJsonPath('data.items.0.is_credit_complete', true)
            ->assertJsonPath('data.items.0.is_potential_graduate', false);

        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001&graduation_status=complete')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/learning/registration/workspace?term=2/2569&search=6650100001&graduation_status=potential')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
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
        $this->assertSame('เจ้าเสด็จ', CurriculumCatalog::resolveGroupSubdistrict('กลุ่ม ศกร.ระดับตำบลเจ้าเสด็จ'));
        $this->assertSame('เสนา', CurriculumCatalog::resolveGroupSubdistrict('ศกร.ระดับตำบลเสนา'));
        $this->assertSame('บ้านแพน', CurriculumCatalog::resolveGroupSubdistrict('กศน.ตำบลบ้านแพน'));

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
        $demoRepo = $this->app->make(DemoStudentRepository::class);
        $customRepo = new class($demoRepo) implements StudentRepository
        {
            public function __construct(private readonly DemoStudentRepository $inner) {}

            public function students(?array $districtIds = null): array
            {
                return $this->inner->students($districtIds);
            }

            public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student
            {
                return $this->inner->find($code, $districtId, $level);
            }

            public function gradesFor(Student $student): array
            {
                $grades = $this->inner->gradesFor($student);
                $grades[] = new Grade(
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

            public function gradesForMany(array $students): array
            {
                return $this->inner->gradesForMany($students);
            }

            public function subjectsFor(Student $student): array
            {
                return $this->inner->subjectsFor($student);
            }

            public function kpchFor(Student $student): array
            {
                return $this->inner->kpchFor($student);
            }

            public function moralFor(Student $student): array
            {
                return $this->inner->moralFor($student);
            }
        };

        $this->app->instance(StudentRepository::class, $customRepo);

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

    public function test_student_reads_only_own_selected_subjects_and_cannot_write(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '2/2569',
            'compulsory_subjects' => [
                ['code' => 'พท11001', 'registered' => true],
                ['code' => 'ทร11001', 'registered' => false],
            ],
            'elective_subjects' => [['code' => 'เลือก101', 'name' => 'วิชาเพิ่มเติม', 'credits' => 2, 'registered' => true]],
            'notes' => 'private staff note',
            'student_info' => ['phone' => 'private phone'],
        ])->assertOk();

        Sanctum::actingAs($this->viewer('student', [], '6650100001'));
        $this->getJson('/api/v1/learning/registration/me?student=6650100002')->assertOk()
            ->assertJsonPath('data.student.code', '6650100001')
            ->assertJsonPath('data.academic_term', '2/2569')
            ->assertJsonCount(1, 'data.compulsory_subjects')
            ->assertJsonPath('data.compulsory_subjects.0.code', 'พท11001')
            ->assertJsonPath('data.elective_subjects.0.code', 'เลือก101')
            ->assertJsonMissingPath('data.student.citizen_id')
            ->assertJsonMissingPath('data.student_info')
            ->assertJsonMissingPath('data.notes');
        $this->getJson('/api/v1/learning/registration/me?term=1/2569')->assertOk()->assertJsonPath('data.academic_term', '1/2569');
        $this->getJson('/api/v1/learning/registration/me?term=bad')->assertUnprocessable();
        $this->getJson('/api/v1/learning/registration/workspace')->assertForbidden();
        $this->getJson('/api/v1/learning/registration/student/6650100002')->assertForbidden();
        $this->postJson('/api/v1/learning/registration/student/6650100001', [])->assertForbidden();
    }

    public function test_imported_subjects_do_not_appear_in_both_columns(): void
    {
        Sanctum::actingAs($this->viewer('student', [], '6650100001'));
        $data = $this->getJson('/api/v1/learning/registration/me?term=2/2568')->assertOk()->json('data');
        $this->assertNotEmpty($data['compulsory_subjects']);
        $this->assertNotEmpty($data['elective_subjects']);
        $this->assertSame([], array_values(array_intersect(
            array_column($data['compulsory_subjects'], 'code'),
            array_column($data['elective_subjects'], 'code'),
        )));
    }

    public function test_student_registration_requires_matching_district_and_identity(): void
    {
        $student = $this->viewer('student', [], '6650100001');
        $student->district_id = District::create(['name' => 'อื่น', 'code' => 'other', 'is_active' => true])->id;
        $student->save();
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/learning/registration/me')->assertNotFound();
        Sanctum::actingAs($this->viewer('student', [], 'missing-student'));
        $this->getJson('/api/v1/learning/registration/me')->assertNotFound();
    }

    public function test_saved_electives_are_reusable_only_in_same_district_and_level(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $otherDistrict = District::create(['name' => 'อื่น', 'code' => 'other', 'is_active' => true]);
        foreach ([
            [$this->district->id, 1, 'shared', 'เลือก101', 'วิชาที่จำไว้'],
            [$this->district->id, 2, 'level2', 'เลือก201', 'ระดับอื่น'],
            [$otherDistrict->id, 1, 'district2', 'เลือก301', 'อำเภออื่น'],
        ] as [$district, $level, $code, $subjectCode, $name]) {
            DB::table('learning_course_registrations')->insert([
                'district_id' => $district, 'student_code' => $code, 'academic_term' => '1/2568',
                'education_level' => $level, 'group_code' => 'test', 'compulsory_subjects' => '[]',
                'elective_subjects' => json_encode([['code' => $subjectCode, 'name' => $name, 'credits' => 3, 'registered' => true, 'remark' => 'private']]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $data = $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569')->assertOk()->json('data');
        $catalog = collect($data['common_electives'])->keyBy('code');
        $this->assertSame('วิชาที่จำไว้', $catalog['เลือก101']['name']);
        $this->assertSame(3, $catalog['เลือก101']['credits']);
        $this->assertArrayNotHasKey('remark', $catalog['เลือก101']);
        $this->assertArrayNotHasKey('registered', $catalog['เลือก101']);
        $this->assertFalse($catalog->has('เลือก201'));
        $this->assertFalse($catalog->has('เลือก301'));
        $this->assertNotContains('เลือก101', array_column($data['elective_subjects'], 'code'));
    }

    public function test_remembered_electives_query_is_reused_without_reusing_student_status(): void
    {
        $viewer = $this->viewer('admin');
        $service = $this->app->make(CourseRegistrationService::class);
        DB::enableQueryLog();
        $first = $service->studentRegistration($viewer, '6650100001', '2/2569');
        $second = $service->studentRegistration($viewer, '6650100002', '2/2569');
        $queries = $this->rememberedElectiveQueryCount();
        $metadataQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], '"education_level" = ?')
            && str_contains($query['query'], 'order by "updated_at" desc')
        );
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'Repeated details in one request should query reusable metadata once.');
        foreach ($metadataQueries as $query) {
            $this->assertStringStartsWith('select "elective_subjects"', $query['query']);
        }
        $this->app->instance('request', Request::create('/'));
        $this->assertSame($second, $service->studentRegistration($viewer, '6650100002', '2/2569'));
        $this->assertNotSame($first['student']['code'], $second['student']['code']);
    }

    public function test_remembered_electives_refresh_on_a_new_request(): void
    {
        $viewer = $this->viewer('admin');
        $service = $this->app->make(CourseRegistrationService::class);
        $service->studentRegistration($viewer, '6650100001', '2/2569');
        DB::table('learning_course_registrations')->insert([
            'district_id' => $this->district->id, 'education_level' => 1,
            'student_code' => 'metadata-source', 'academic_term' => '1/2569',
            'group_code' => 'test', 'compulsory_subjects' => '[]',
            'elective_subjects' => json_encode([['code' => 'เลือก999', 'name' => 'ใหม่', 'credits' => 2, 'remark' => 'private', 'registered' => true]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance('request', Request::create('/'));
        $data = $service->studentRegistration($viewer, '6650100002', '2/2569');
        $subject = collect($data['common_electives'])->firstWhere('code', 'เลือก999');
        $this->assertSame('ใหม่', $subject['name']);
        $this->assertArrayNotHasKey('remark', $subject);
        $this->assertArrayNotHasKey('registered', $subject);
    }

    public function test_save_invalidates_remembered_metadata_for_every_level_in_the_district(): void
    {
        $viewer = $this->viewer('admin');
        $service = $this->app->make(CourseRegistrationService::class);
        DB::enableQueryLog();
        $service->studentRegistration($viewer, '6650100001', '2/2569');
        $service->studentRegistration($viewer, '6650200003', '2/2569');
        $service->save($viewer, '6650100001', [
            'academic_term' => '2/2569', 'compulsory_subjects' => [],
            'elective_subjects' => [['code' => 'เลือก999', 'name' => 'บันทึกใหม่', 'credits' => 2, 'registered' => true]],
        ]);
        $service->studentRegistration($viewer, '6650200003', '2/2569');
        $this->assertSame(4, $this->rememberedElectiveQueryCount());
        DB::disableQueryLog();
        $data = $service->studentRegistration($viewer, '6650100002', '2/2569');
        $this->assertSame('บันทึกใหม่', collect($data['common_electives'])->firstWhere('code', 'เลือก999')['name']);
    }

    private function rememberedElectiveQueryCount(): int
    {
        return count(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "learning_course_registrations"')
            && str_contains($query['query'], '"education_level" = ?')
            && str_contains($query['query'], 'order by "updated_at" desc')
        ));
    }

    public function test_blank_current_results_are_pending_instead_of_absent_even_when_exam_flag_is_false(): void
    {
        $this->registrationGrades([
            new Grade('6650100001', 'ทร11001', 'ทักษะการเรียนรู้', 5, 'compulsory', '2/2569', null, examAttended: false),
            new Grade('6650100001', 'อช12001', 'โครงการอาชีพ', 3, 'elective', '1/2569', 'ข', examAttended: false),
            new Grade('6650100001', 'อช12001', 'โครงการอาชีพ', 3, 'elective', '2/2569', '', examAttended: false),
            new Grade('6650100001', 'พว12010', 'พลังงาน', 2, 'elective', '2/2569', '-', examAttended: false),
            new Grade('6650100001', 'สค12021', 'การเงิน', 2, 'elective', '2/2569', 'ข', examAttended: false),
            new Grade('6650100001', 'สค12025', 'ประวัติศาสตร์', 2, 'elective', '2/2569', '0', examAttended: true),
            new Grade('6650100001', 'สค12026', 'การป้องกันการทุจริต', 2, 'elective', '1/2569', 'ข', examAttended: false),
            new Grade('6650100001', 'สค12026', 'การป้องกันการทุจริต', 2, 'elective', '2/2569', 'ม', examAttended: false),
        ]);
        Sanctum::actingAs($this->viewer('admin'));
        $data = $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569')->assertOk()->json('data');
        $subjects = collect(array_merge($data['compulsory_subjects'], $data['elective_subjects']))->keyBy('code');
        foreach (['ทร11001', 'อช12001', 'พว12010', 'สค12026'] as $code) {
            $status = $subjects[$code]['course_status'];
            $this->assertSame('pending_grade', $status['status']);
            $this->assertSame('2/2569', $status['term']);
            $this->assertNull($status['grade']);
            $this->assertNull($status['warning']);
            $this->assertFalse($status['has_grade']);
        }
        $this->assertSame('absent_exam', $subjects['สค12021']['course_status']['status']);
        $this->assertSame('failed', $subjects['สค12025']['course_status']['status']);
    }

    public function test_saved_new_term_registration_is_pending_without_reusing_previous_absence(): void
    {
        $this->registrationGrades([
            new Grade('6650100001', 'อช12001', 'โครงการอาชีพ', 3, 'elective', '1/2569', 'ข', examAttended: false),
        ]);
        Sanctum::actingAs($this->viewer('admin'));
        $this->postJson('/api/v1/learning/registration/student/6650100001', [
            'academic_term' => '2/2569', 'compulsory_subjects' => [],
            'elective_subjects' => [['code' => 'อช12001', 'name' => 'โครงการอาชีพ', 'credits' => 3, 'registered' => true]],
        ])->assertOk()->assertJsonPath('data.elective_subjects.0.course_status.status', 'pending_grade')
            ->assertJsonPath('data.elective_subjects.0.course_status.term', '2/2569');
        $this->getJson('/api/v1/learning/registration/student/6650100001?term=2/2569')->assertOk()
            ->assertJsonPath('data.elective_subjects.0.course_status.status', 'pending_grade');
        $this->getJson('/api/v1/learning/registration/student/6650100001?term=1/2569')->assertOk()
            ->assertJsonPath('data.elective_subjects.0.course_status.status', 'absent_exam');
    }

    /** @param list<Grade> $grades */
    private function registrationGrades(array $grades): void
    {
        $demo = $this->app->make(DemoStudentRepository::class);
        $repository = \Mockery::mock(StudentRepository::class);
        foreach (['students', 'find', 'subjectsFor', 'kpchFor', 'moralFor'] as $method) {
            $repository->shouldReceive($method)->andReturnUsing(static fn (...$args) => $demo->{$method}(...$args));
        }
        $repository->shouldReceive('gradesForMany')->andReturnUsing(static function (array $students) use ($demo, $grades): array {
            $result = $demo->gradesForMany($students);
            foreach ($students as $student) {
                if ($student->code === '6650100001') {
                    $result["{$student->districtId}|{$student->level}|{$student->code}"] = $grades;
                }
            }

            return $result;
        });
        $repository->shouldReceive('gradesFor')->andReturn($grades);
        $this->app->instance(StudentRepository::class, $repository);
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
