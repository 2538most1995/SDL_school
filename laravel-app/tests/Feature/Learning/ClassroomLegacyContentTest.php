<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClassroomLegacyContentTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'เสนา', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        Sanctum::actingAs($this->teacher);
    }

    public function test_resource_filters_run_before_limit_and_preserve_group_and_district_scope(): void
    {
        $course = $this->resource(['title' => 'เอกสารรายวิชา']);
        $shared = $this->resource(['title' => 'เอกสารส่วนกลาง', 'subject_code' => null, 'education_level' => null]);
        $video = $this->resource(['title' => 'คลิปวิทยาศาสตร์', 'resource_type' => 'youtube']);
        $this->resource(['title' => 'วิชาอื่น', 'subject_code' => 'OTHER']);
        $this->resource(['title' => 'ระดับอื่น', 'education_level' => 2]);
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-B']]);
        $this->resource(['title' => 'กลุ่มอื่น', 'uploaded_by' => $otherTeacher->id, 'target_group' => 'SENA-M3-B']);
        $otherDistrict = District::create(['name' => 'อำเภออื่น', 'code' => 'other', 'is_active' => true]);
        $this->resource(['title' => 'อำเภออื่น', 'district_id' => $otherDistrict->id]);
        for ($index = 0; $index < 255; $index++) {
            $this->resource(['title' => 'ไม่เกี่ยวข้อง '.$index, 'subject_code' => 'OTHER', 'created_at' => now()->addDay()]);
        }
        $url = '/api/v1/learning/resources?'.http_build_query(['subject_code' => 'พว31001', 'education_level' => 3]);
        $response = $this->getJson($url.'&classroom_kind=knowledge')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([(string) $course, (string) $shared], array_column($response->json('data'), 'id'));
        $this->getJson($url.'&classroom_kind=video')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $video)
            ->assertJsonPath('data.0.education_level', 3)->assertJsonPath('data.0.resource_type', 'youtube');
        $this->getJson('/api/v1/learning/resources?shared_only=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $shared);
    }

    public function test_student_resources_require_registration_level_and_group_even_with_demo_rosters(): void
    {
        $this->resource(['title' => 'เรียนได้']);
        $this->resource(['title' => 'กลุ่มอื่น', 'target_group' => 'SENA-M3-B']);
        $this->resource(['title' => 'ระดับอื่น', 'education_level' => 2]);
        $this->resource(['title' => 'วิชาอื่น', 'subject_code' => 'OTHER']);
        Sanctum::actingAs($this->student());
        config(['system_data.enabled' => false]);
        $this->getJson('/api/v1/learning/resources?'.http_build_query(['subject_code' => 'พว31001', 'education_level' => 3]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'เรียนได้')->assertJsonPath('meta.source', 'system_database');
        $this->getJson('/api/v1/learning/resources?'.http_build_query(['subject_code' => 'OTHER', 'education_level' => 3]))->assertNotFound();
        $this->getJson('/api/v1/learning/resources?'.http_build_query(['subject_code' => 'พว31001', 'education_level' => 2]))->assertNotFound();
    }

    public function test_assignment_course_filter_precedes_limit_and_restricts_selection_and_roster(): void
    {
        $wanted = $this->assignment(['title' => 'งานวิทยาศาสตร์']);
        $legacy = $this->assignment(['title' => 'งานเดิมไม่ระบุระดับ', 'education_level' => null]);
        $other = $this->assignment(['title' => 'วิชาอื่น', 'subject_code' => 'OTHER']);
        $this->assignment(['title' => 'ระดับอื่น', 'education_level' => 2]);
        $this->assignment(['title' => 'งานครูอื่น', 'created_by' => User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']])->id]);
        $otherDistrict = District::create(['name' => 'อำเภออื่น', 'code' => 'other', 'is_active' => true]);
        $foreign = $this->assignment(['title' => 'อำเภออื่น', 'district_id' => $otherDistrict->id]);
        for ($index = 0; $index < 255; $index++) {
            $this->assignment(['title' => 'ไม่เกี่ยวข้อง '.$index, 'subject_code' => 'OTHER', 'created_at' => now()->addDay()]);
        }
        $url = '/api/v1/learning/assignments?'.http_build_query(['subject_code' => 'พว31001', 'education_level' => 3]);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.subjects')->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.subjects.0.code', 'พว31001')->assertJsonPath('data.subjects.0.level', 3);
        $this->assertEqualsCanonicalizing([(string) $wanted, (string) $legacy], array_column($response->json('data.assignments'), 'id'));
        $this->getJson($url.'&assignment_id='.$other)->assertNotFound();
        $this->getJson($url.'&assignment_id='.$wanted)->assertOk()->assertJsonPath('data.students.0.student_code', '6650300005');
        $this->getJson('/api/v1/learning/assignments?assignment_id='.$wanted)->assertOk()
            ->assertJsonPath('data.selected_assignment.id', (string) $wanted)->assertJsonCount(250, 'data.assignments');
        $this->getJson('/api/v1/learning/assignments?assignment_id='.$foreign)->assertNotFound();
        Sanctum::actingAs($this->student());
        $this->getJson($url.'&assignment_id='.$wanted)->assertOk()->assertJsonCount(0, 'data.students')->assertJsonPath('data.selected_assignment.id', (string) $wanted);
        $this->getJson('/api/v1/learning/assignments?'.http_build_query(['subject_code' => 'OTHER', 'education_level' => 3]))->assertNotFound();
    }

    public function test_classroom_content_filters_reject_incomplete_and_invalid_pairs(): void
    {
        foreach (['resources', 'assignments'] as $kind) {
            $this->getJson('/api/v1/learning/'.$kind.'?education_level=3')->assertUnprocessable();
            $this->getJson('/api/v1/learning/'.$kind.'?subject_code=OTHER')->assertUnprocessable();
            $this->getJson('/api/v1/learning/'.$kind.'?subject_code=OTHER&education_level=4')->assertUnprocessable();
        }
        $this->getJson('/api/v1/learning/resources?classroom_kind=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/learning/resources?subject_code=OTHER&education_level=3&shared_only=1')->assertUnprocessable();
        $this->getJson('/api/v1/learning/resources?shared_only=maybe')->assertUnprocessable();
    }

    public function test_file_work_and_resources_use_web_registered_courses_and_preserve_student_submissions(): void
    {
        DB::table('learning_course_registrations')->insert([
            'district_id' => $this->district->id, 'academic_term' => '2/2568', 'education_level' => 3,
            'student_code' => '6650300005', 'compulsory_subjects' => '[]', 'elective_subjects' => json_encode([
                ['code' => 'WEB001', 'name' => 'วิชาเลือกออนไลน์', 'registered' => true],
                ['code' => 'NO001', 'registered' => false], ['code' => 'TR001', 'registered' => true, 'transferred' => true],
            ]),
        ]);
        $this->resource(['title' => 'เอกสารวิชาเว็บ', 'subject_code' => 'WEB001']);
        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'งานวิชาเลือกออนไลน์', 'subject_code' => 'WEB001', 'education_level' => 3,
            'target_group' => 'SENA-M3-A', 'max_score' => 20, 'due_at' => now()->addDay()->toDateTimeString(), 'status' => 'open',
        ])->assertCreated();
        $id = $created->json('data.id');
        Sanctum::actingAs($this->student());
        $this->getJson('/api/v1/learning/resources?subject_code=WEB001&education_level=3')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/learning/assignments?subject_code=WEB001&education_level=3')->assertOk()->assertJsonPath('data.selected_assignment.id', $id);
        $this->postJson('/api/v1/learning/assignments/'.$id.'/submit', ['submission_type' => 'link', 'url' => 'https://example.test/work'])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        foreach (['NO001', 'TR001'] as $code) {
            $this->getJson('/api/v1/learning/resources?subject_code='.$code.'&education_level=3')->assertNotFound();
            $this->getJson('/api/v1/learning/assignments?subject_code='.$code.'&education_level=3')->assertNotFound();
        }
    }

    public function test_shared_documents_can_be_edited_without_forcing_a_subject_and_keep_office_extensions(): void
    {
        $id = $this->resource(['subject_code' => null, 'education_level' => null]);
        $this->patchJson('/api/v1/learning/resources/'.$id, [
            'title' => 'เอกสารส่วนกลางปรับปรุง', 'subject' => '', 'resource_type' => 'link', 'level' => '',
            'target_group' => 'SENA-M3-A', 'url' => 'https://example.test/shared',
        ])->assertOk();
        $this->getJson('/api/v1/learning/resources?shared_only=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_code', '')->assertJsonPath('data.0.education_level', null);
        foreach (['docx', 'pptx', 'zip'] as $extension) {
            $this->resource(['resource_type' => 'file', 'storage_path' => 'learning/resources/private/example.'.$extension]);
        }
        $extensions = array_column(array_column($this->getJson('/api/v1/learning/resources?classroom_kind=knowledge')->assertOk()->json('data'), 'raw'), 'file_extension');
        foreach (['docx', 'pptx', 'zip'] as $extension) {
            $this->assertContains($extension, $extensions);
        }
    }

    private function resource(array $overrides = []): int
    {
        return DB::table('learning_resources')->insertGetId([
            'district_id' => $this->district->id, 'uploaded_by' => $this->teacher->id, 'title' => 'สื่อ', 'description' => '',
            'subject_code' => 'พว31001', 'education_level' => 3, 'target_group' => 'SENA-M3-A', 'resource_type' => 'link', 'external_url' => 'https://example.test/document',
            'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);
    }

    private function assignment(array $overrides = []): int
    {
        return DB::table('learning_assignments')->insertGetId([
            'district_id' => $this->district->id, 'created_by' => $this->teacher->id, 'academic_term' => '2/2568',
            'subject_code' => 'พว31001', 'subject_name' => 'วิทยาศาสตร์', 'education_level' => 3, 'title' => 'งาน', 'instructions' => '',
            'target_type' => 'group', 'target_value' => 'SENA-M3-A', 'max_score' => 20, 'due_at' => now()->addDay(), 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '6650300005', 'username' => 'student-6650300005']);
    }
}
