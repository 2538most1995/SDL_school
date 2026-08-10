<?php

namespace Tests\Feature\Learning;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LearningContentWriteTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('system_data.enabled', true);
        config()->set('system_data.write_enabled', true);
        $this->district = District::create([
            'name' => 'อำเภอเสนา',
            'code' => 'sena',
            'is_active' => true,
        ]);
    }

    public function test_teacher_can_manage_only_owned_content_in_assigned_group(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'ใบงานภาษาไทย',
            'subject' => 'พท31001',
            'description' => 'บทที่ 1',
            'due_at' => '2026-08-01 12:00:00',
            'target_group' => 'G-01',
            'target_mode' => 'group',
            'status' => 'open',
        ])->assertCreated();
        $id = (int) $created->json('data.id');

        $this->patchJson("/api/v1/learning/assignments/{$id}", [
            'title' => 'ใบงานภาษาไทย (แก้ไข)',
            'subject' => 'พท31001',
            'description' => '',
            'due_at' => '2026-08-02 12:00:00',
            'target_group' => 'G-01',
            'target_mode' => 'group',
            'status' => 'open',
        ])->assertOk();

        $this->assertDatabaseHas('learning_assignments', [
            'id' => $id,
            'district_id' => $this->district->id,
            'created_by' => $teacher->id,
            'subject_code' => 'พท31001',
            'target_type' => 'group',
            'target_value' => 'G-01',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'learning.assignments.updated',
            'district_id' => $this->district->id,
        ]);

        $otherTeacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
        ]);
        $otherId = (int) DB::table('learning_assignments')->insertGetId([
            'district_id' => $this->district->id,
            'created_by' => $otherTeacher->id,
            'title' => 'ของครูอื่น',
            'subject_code' => 'พท31001',
            'due_at' => now(),
            'target_type' => 'group',
            'target_value' => 'G-01',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/learning/assignments/{$otherId}")->assertNotFound();
        $this->postJson('/api/v1/learning/assignments', [
            'title' => 'ข้ามกลุ่ม',
            'subject' => 'พท31001',
            'due_at' => '2026-08-01 12:00:00',
            'target_group' => 'G-99',
            'target_mode' => 'group',
            'status' => 'open',
        ])->assertForbidden();
    }

    public function test_student_cannot_mutate_learning_content(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
        ]));

        $this->postJson('/api/v1/learning/assignments', [])->assertForbidden();
    }

    public function test_learning_request_repairs_schema_missing_from_git_only_deployment_before_saving(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        Schema::table('learning_resources', function ($table): void {
            $table->dropIndex(['education_level']);
            $table->dropIndex(['target_group']);
            $table->dropColumn(['education_level', 'target_group']);
        });
        Schema::table('learning_calendar_events', function ($table): void {
            $table->dropColumn(['location', 'image_path', 'image_updated_at']);
        });
        Schema::table('learning_assignments', function ($table): void {
            $table->dropColumn('instructions');
        });
        Schema::table('learning_submissions', function ($table): void {
            $table->dropColumn('attachment_disk');
        });
        Schema::table('learning_lesson_plans', function ($table): void {
            $table->dropColumn('objectives');
        });
        Schema::drop('audit_logs');

        $this->postJson('/api/v1/learning/resources', [
            'title' => 'สื่อหลัง deploy ผ่าน Git',
            'subject' => 'พท31001',
            'description' => 'ทดสอบ schema repair',
            'resource_type' => 'link',
            'url' => 'https://example.test/material',
            'level' => '3',
            'target_group' => 'G-01',
        ])->assertCreated();

        $this->assertTrue(Schema::hasColumn('learning_resources', 'education_level'));
        $this->assertTrue(Schema::hasColumn('learning_resources', 'target_group'));
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'image_path'));
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'image_updated_at'));
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'location'));
        $this->assertTrue(Schema::hasColumn('learning_assignments', 'instructions'));
        $this->assertTrue(Schema::hasColumn('learning_submissions', 'attachment_disk'));
        $this->assertTrue(Schema::hasColumn('learning_lesson_plans', 'objectives'));
        $this->assertDatabaseHas('learning_resources', [
            'title' => 'สื่อหลัง deploy ผ่าน Git',
            'education_level' => 3,
            'target_group' => 'G-01',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'learning.resources.created',
            'district_id' => $this->district->id,
        ]);

        $activity = $this->post('/api/v1/learning/calendar', [
            'title' => 'กิจกรรมหลัง deploy ผ่าน Git',
            'event_type' => 'activity',
            'event_date' => '2026-08-22',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'target_group' => 'G-01',
            'image' => UploadedFile::fake()->image('activity.jpg', 1200, 675),
        ], ['Accept' => 'application/json'])->assertCreated();
        $imagePath = (string) DB::table('learning_calendar_events')
            ->where('id', (int) $activity->json('data.id'))
            ->value('image_path');

        Storage::disk('local')->assertExists($imagePath);
    }

    public function test_teacher_can_publish_activity_image_that_targeted_students_can_view(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        $created = $this->post('/api/v1/learning/calendar', [
            'title' => 'กิจกรรมปลูกต้นไม้',
            'event_type' => 'activity',
            'event_date' => '2026-08-21',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'location' => 'ชุมชนบ้านแถว',
            'target_group' => 'G-01',
            'notes' => 'แต่งกายให้เหมาะสม',
            'image' => UploadedFile::fake()->image('activity.jpg', 1200, 675),
        ], ['Accept' => 'application/json'])->assertCreated();
        $eventId = (int) $created->json('data.id');
        $path = (string) DB::table('learning_calendar_events')->where('id', $eventId)->value('image_path');
        Storage::disk('local')->assertExists($path);

        $student = User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'event-'.$eventId)
            ->assertJsonPath('data.0.type', 'activity')
            ->assertJsonPath('data.0.description', 'แต่งกายให้เหมาะสม')
            ->assertJsonPath('data.0.image_url', fn (string $url): bool => str_contains($url, "/calendar/{$eventId}/image"));
        $this->get("/api/v1/learning/calendar/{$eventId}/image")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->get("/api/v1/learning/calendar/{$eventId}/image")->assertUnprocessable();
        $this->get("/api/v1/learning/calendar/{$eventId}/image?district_id={$this->district->id}")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-99'],
        ]));
        $this->getJson('/api/v1/learning/calendar')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/learning/calendar/{$eventId}/image")->assertNotFound();
    }

    public function test_calendar_activity_can_span_multiple_days(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => [],
        ]);
        Sanctum::actingAs($teacher);

        $created = $this->postJson('/api/v1/learning/calendar', [
            'title' => 'ค่ายพัฒนาผู้เรียน',
            'event_type' => 'activity',
            'event_date' => '2026-08-21',
            'end_date' => '2026-08-23',
            'start_time' => '08:30',
            'end_time' => '16:00',
            'target_group' => '',
        ])->assertCreated()
            ->assertJsonPath('data.end_date', '2026-08-23');
        $eventId = (int) $created->json('data.id');

        $this->assertDatabaseHas('learning_calendar_events', [
            'id' => $eventId,
            'starts_at' => '2026-08-21 08:30:00',
            'ends_at' => '2026-08-23 16:00:00',
            'target_type' => 'all',
        ]);
        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonPath('data.0.raw.event_date', '2026-08-21')
            ->assertJsonPath('data.0.raw.end_date', '2026-08-23');

        $this->postJson('/api/v1/learning/calendar', [
            'title' => 'เวลาผิดลำดับ',
            'event_type' => 'activity',
            'event_date' => '2026-08-21',
            'end_date' => '2026-08-21',
            'start_time' => '16:00',
            'end_time' => '08:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_time');
    }

    public function test_student_group_name_alias_can_view_existing_activity_and_image(): void
    {
        Storage::fake('local');
        $studentModel = new Student(
            code: 'ST-ALIAS',
            districtId: $this->district->id,
            districtName: $this->district->name,
            prefix: 'นาย',
            firstName: 'นักศึกษา',
            lastName: 'กลุ่มจริง',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: 'G-01',
            groupName: 'สำโรงชัย กลุ่ม 1',
            enrollmentTerm: '1/2568',
            currentTerm: '1/2569',
            status: 'active',
            statusLabel: 'กำลังศึกษา',
            gpax: 3.0,
            creditsEarned: 30,
            creditsRequired: 76,
            kpchHours: 50,
            moralResult: 'ดี',
        );
        $repository = $this->createMock(StudentRepository::class);
        $repository->method('students')->willReturn([$studentModel]);
        $this->app->instance(StudentRepository::class, $repository);

        $creator = User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);
        $eventId = (int) DB::table('learning_calendar_events')->insertGetId([
            'district_id' => $this->district->id,
            'created_by' => $creator->id,
            'title' => 'กิจกรรมที่บันทึกด้วยชื่อกลุ่มเดิม',
            'event_type' => 'activity',
            'starts_at' => '2026-08-25 09:00:00',
            'ends_at' => '2026-08-25 12:00:00',
            'target_type' => 'group',
            'target_value' => 'สำโรงชัย กลุ่ม 1',
            'image_path' => "learning/calendar/{$this->district->id}/pending/activity.jpg",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $imagePath = "learning/calendar/{$this->district->id}/{$eventId}/activity.jpg";
        DB::table('learning_calendar_events')->where('id', $eventId)->update(['image_path' => $imagePath]);
        Storage::disk('local')->put($imagePath, 'image-data');

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => 'ST-ALIAS',
            'assigned_groups' => [],
        ]));

        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'event-'.$eventId)
            ->assertJsonPath('data.0.image_url', fn (string $url): bool => str_contains($url, "/calendar/{$eventId}/image"));
        $this->get("/api/v1/learning/calendar/{$eventId}/image")->assertOk();
    }

    public function test_learning_reads_use_only_canonical_system_tables(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        $assignment = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'ใบงานในระบบ',
            'subject' => 'พท31001',
            'description' => 'รายละเอียด',
            'due_at' => '2026-08-20 12:00:00',
            'target_group' => 'G-01',
            'target_mode' => 'group',
            'status' => 'open',
        ])->assertCreated();
        $this->postJson('/api/v1/learning/resources', [
            'title' => 'สื่อในระบบ',
            'subject' => 'พท31001',
            'description' => 'รายละเอียด',
            'resource_type' => 'link',
            'url' => 'https://example.test/material',
            'level' => '3',
            'target_group' => 'G-01',
        ])->assertCreated();
        $this->postJson('/api/v1/learning/lesson-plans', [
            'title' => 'แผนในระบบ',
            'subject' => 'พท31001',
            'level' => '3',
            'semester' => '1/2569',
            'objectives' => 'วัตถุประสงค์',
            'activities' => 'กิจกรรม',
            'assessment' => 'ประเมิน',
        ])->assertCreated();
        $this->postJson('/api/v1/learning/calendar', [
            'title' => 'พบกลุ่มในระบบ',
            'event_date' => '2026-08-21',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'location' => 'ห้อง 1',
            'target_group' => 'G-01',
            'notes' => 'เตรียมหนังสือ',
        ])->assertCreated();

        $studentId = DB::table('students')->insertGetId([
            'district_id' => $this->district->id,
            'student_code' => 'ST001',
            'first_name' => 'นักศึกษา',
            'last_name' => 'ทดสอบ',
            'education_level' => 3,
            'group_code' => 'G-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learning_submissions')->insert([
            'assignment_id' => (int) $assignment->json('data.id'),
            'student_id' => $studentId,
            'status' => 'reviewed',
            'score' => 8,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learning_schedules')->insert([
            'district_id' => $this->district->id,
            'academic_term' => '1/2569',
            'subject_code' => 'พท31001',
            'subject_name' => 'ภาษาไทย',
            'group_code' => 'G-01',
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-08-22 09:00:00',
            'ends_at' => '2026-08-22 11:00:00',
            'room' => 'ห้อง 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            '/api/v1/learning/assignments',
            '/api/v1/learning/resources',
            '/api/v1/learning/lesson-plans',
            '/api/v1/learning/calendar',
            '/api/v1/learning/schedule',
            '/api/v1/learning/scores',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('meta.source', 'system_database');
        }
    }
}
