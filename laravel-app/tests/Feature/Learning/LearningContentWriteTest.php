<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
