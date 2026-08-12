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

    public function test_teacher_can_store_private_resource_file_and_only_targeted_viewers_can_download_it(): void
    {
        Storage::fake('local');
        $studentModel = new Student(
            code: 'ST-RESOURCE',
            districtId: $this->district->id,
            districtName: $this->district->name,
            prefix: 'นาย',
            firstName: 'นักศึกษา',
            lastName: 'ทดสอบสื่อ',
            level: 3,
            levelLabel: 'มัธยมศึกษาตอนปลาย',
            groupCode: 'G-01',
            groupName: 'กลุ่ม 1',
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
        $levelTwoStudent = new Student(
            code: 'ST-LEVEL-2',
            districtId: $this->district->id,
            districtName: $this->district->name,
            prefix: 'นางสาว',
            firstName: 'นักศึกษา',
            lastName: 'ต่างระดับ',
            level: 2,
            levelLabel: 'มัธยมศึกษาตอนต้น',
            groupCode: 'G-01',
            groupName: 'กลุ่ม 1',
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
        $repository->method('students')->willReturn([$studentModel, $levelTwoStudent]);
        $this->app->instance(StudentRepository::class, $repository);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        $created = $this->post('/api/v1/learning/resources', [
            'title' => 'คู่มือทดสอบ',
            'subject' => 'พท31001',
            'description' => 'เอกสารสำหรับกลุ่มที่รับผิดชอบ',
            'resource_type' => 'pdf',
            'level' => '3',
            'target_group' => 'G-01',
            'file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $resourceId = (int) $created->json('data.id');
        $path = (string) DB::table('learning_resources')->where('id', $resourceId)->value('storage_path');

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('learning_resources', [
            'id' => $resourceId,
            'district_id' => $this->district->id,
            'uploaded_by' => $teacher->id,
            'resource_type' => 'pdf',
            'storage_disk' => 'local',
            'external_url' => null,
            'target_group' => 'G-01',
        ]);
        $this->patchJson("/api/v1/learning/resources/{$resourceId}", [
            'title' => 'คู่มือทดสอบ (แก้ไข)',
            'subject' => 'พท31001',
            'description' => 'แก้ข้อมูลโดยคงไฟล์เดิม',
            'resource_type' => 'pdf',
            'level' => '3',
            'target_group' => 'G-01',
        ])->assertOk();
        $this->assertSame($path, DB::table('learning_resources')->where('id', $resourceId)->value('storage_path'));
        Storage::disk('local')->assertExists($path);
        $audit = DB::table('audit_logs')->where('event', 'learning.resources.updated')->latest('id')->first();
        $this->assertArrayNotHasKey('storage_path', json_decode((string) $audit->before, true, flags: JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('url', json_decode((string) $audit->after, true, flags: JSON_THROW_ON_ERROR));
        $this->getJson('/api/v1/learning/resources')
            ->assertOk()
            ->assertJsonPath('data.0.file_url', fn (string $url): bool => str_contains($url, "/resources/{$resourceId}/file"));
        $this->get("/api/v1/learning/resources/{$resourceId}/file")->assertOk();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => 'ST-NOT-FOUND',
            'assigned_groups' => ['G-02'],
        ]));
        $this->get("/api/v1/learning/resources/{$resourceId}/file")->assertNotFound();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => 'ST-LEVEL-2',
            'assigned_groups' => ['G-01'],
        ]));
        $this->getJson('/api/v1/learning/resources')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/learning/resources/{$resourceId}/file")->assertNotFound();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => 'ST-RESOURCE',
            'assigned_groups' => ['G-01'],
        ]));
        $this->get("/api/v1/learning/resources/{$resourceId}/file")->assertOk();

        Sanctum::actingAs($teacher);
        $this->deleteJson("/api/v1/learning/resources/{$resourceId}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_resource_write_contract_rejects_unsafe_files_and_values_that_exceed_schema_limits(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
        ]));

        $this->post('/api/v1/learning/resources', [
            'title' => 'ไฟล์ไม่ปลอดภัย',
            'subject' => 'พท31001',
            'resource_type' => 'file',
            'file' => UploadedFile::fake()->create('payload.php', 10, 'text/x-php'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->postJson('/api/v1/learning/resources', [
            'title' => 'รหัสวิชายาวเกิน schema',
            'subject' => str_repeat('ก', 33),
            'resource_type' => 'link',
            'url' => 'https://example.test/material',
        ])->assertUnprocessable()->assertJsonValidationErrors('subject');

        $longUrl = 'https://example.test/material?token='.str_repeat('a', 500);
        $this->postJson('/api/v1/learning/resources', [
            'title' => 'ลิงก์ยาวที่ยังอยู่ในขอบเขต',
            'subject' => 'พท31001',
            'resource_type' => 'link',
            'url' => $longUrl,
        ])->assertCreated();
        $this->assertDatabaseHas('learning_resources', ['external_url' => $longUrl]);
    }

    public function test_resource_form_receives_scoped_group_options_and_can_save_youtube_for_every_group(): void
    {
        $batch = 'import_1700000300_groups';
        $historyId = DB::table('import_history')->insertGetId([
            'file_name' => 'itw51.zip',
            'saved_file_name' => 'itw51.zip',
            'batch_key' => $batch,
            'file_size_kb' => 100,
            'level' => 'ทุกระดับ',
            'file_count' => 1,
            'status' => 'success',
            'district_id' => $this->district->id,
            'created_at' => now(),
        ]);
        DB::table('import_batches')->insert([
            'district_id' => $this->district->id,
            'import_history_id' => $historyId,
            'batch_key' => $batch,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupTable = 'db_'.$batch.'_2_group';
        Schema::create($groupTable, function ($table): void {
            $table->id();
            $table->string('grp_code');
            $table->string('grp_name');
            $table->string('grp_class');
        });
        DB::table($groupTable)->insert([
            ['grp_code' => 'G-01', 'grp_name' => 'กลุ่มบางนมโค', 'grp_class' => '2'],
            ['grp_code' => 'G-02', 'grp_name' => 'กลุ่มสามกอ', 'grp_class' => '2'],
        ]);
        Schema::table('learning_resources', function ($table): void {
            $table->string('subject');
            $table->text('url');
            $table->string('level');
            $table->unsignedBigInteger('created_by');
        });
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => ['G-01'],
        ]);
        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/learning/resources')
            ->assertOk()
            ->assertJsonCount(1, 'meta.available_groups')
            ->assertJsonPath('meta.available_groups.0.code', 'G-01')
            ->assertJsonPath('meta.available_groups.0.name', 'กลุ่มบางนมโค');

        $this->postJson('/api/v1/learning/resources', [
            'title' => 'ทักษะการเรียนรู้',
            'subject' => 'ทร21001',
            'description' => 'ทดสอบ',
            'resource_type' => 'youtube',
            'url' => 'https://www.youtube.com/watch?v=bV3W62zZcTc',
            'level' => '2',
            'target_group' => '',
        ])->assertCreated();
        $this->assertDatabaseHas('learning_resources', [
            'title' => 'ทักษะการเรียนรู้',
            'external_url' => 'https://www.youtube.com/watch?v=bV3W62zZcTc',
            'education_level' => 2,
            'target_group' => '',
            'subject' => 'ทร21001',
            'url' => 'https://www.youtube.com/watch?v=bV3W62zZcTc',
            'level' => '2',
            'created_by' => $teacher->id,
        ]);
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
            $table->dropIndex(['featured_on_dashboard']);
            $table->dropColumn(['location', 'image_path', 'image_updated_at', 'daily_schedule', 'external_url', 'featured_on_dashboard']);
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
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'daily_schedule'));
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'external_url'));
        $this->assertTrue(Schema::hasColumn('learning_calendar_events', 'featured_on_dashboard'));
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
            'end_time' => '14:00',
            'daily_schedule' => [
                ['date' => '2026-08-21', 'start_time' => '08:30', 'end_time' => '16:00'],
                ['date' => '2026-08-22', 'start_time' => '10:00', 'end_time' => '15:30'],
                ['date' => '2026-08-23', 'start_time' => '09:15', 'end_time' => '14:00'],
            ],
            'external_url' => 'https://example.test/activity-registration',
            'target_group' => '',
        ])->assertCreated()
            ->assertJsonPath('data.end_date', '2026-08-23')
            ->assertJsonPath('data.daily_schedule.1.start_time', '10:00');
        $eventId = (int) $created->json('data.id');

        $this->assertDatabaseHas('learning_calendar_events', [
            'id' => $eventId,
            'starts_at' => '2026-08-21 08:30:00',
            'ends_at' => '2026-08-23 14:00:00',
            'target_type' => 'all',
            'external_url' => 'https://example.test/activity-registration',
        ]);
        $storedSchedule = json_decode((string) DB::table('learning_calendar_events')->where('id', $eventId)->value('daily_schedule'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('10:00', $storedSchedule[1]['start_time']);
        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonPath('data.0.raw.event_date', '2026-08-21')
            ->assertJsonPath('data.0.raw.end_date', '2026-08-23')
            ->assertJsonPath('data.0.schedule_days.1.start_time', '10:00')
            ->assertJsonPath('data.0.external_url', 'https://example.test/activity-registration');

        $this->postJson('/api/v1/learning/calendar', [
            'title' => 'เวลาผิดลำดับ',
            'event_type' => 'activity',
            'event_date' => '2026-08-21',
            'end_date' => '2026-08-21',
            'start_time' => '16:00',
            'end_time' => '08:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_time');

        $this->postJson('/api/v1/learning/calendar', [
            'title' => 'กำหนดเวลาไม่ครบวัน',
            'event_type' => 'activity',
            'event_date' => '2026-08-21',
            'end_date' => '2026-08-23',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'daily_schedule' => [
                ['date' => '2026-08-21', 'start_time' => '09:00', 'end_time' => '12:00'],
                ['date' => '2026-08-23', 'start_time' => '09:00', 'end_time' => '12:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('daily_schedule');
    }

    public function test_admin_can_choose_one_dashboard_event_and_teacher_cannot_replace_it(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);
        Sanctum::actingAs($admin);

        $firstId = (int) $this->postJson('/api/v1/learning/calendar', [
            'title' => 'กิจกรรมหน้าแรกเดิม',
            'event_type' => 'activity',
            'event_date' => '2026-08-24',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'featured_on_dashboard' => true,
        ])->assertCreated()->json('data.id');
        $secondId = (int) $this->postJson('/api/v1/learning/calendar', [
            'title' => 'กิจกรรมหน้าแรกใหม่',
            'event_type' => 'meeting',
            'event_date' => '2026-08-25',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'featured_on_dashboard' => true,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('learning_calendar_events', ['id' => $firstId, 'featured_on_dashboard' => false]);
        $this->assertDatabaseHas('learning_calendar_events', ['id' => $secondId, 'featured_on_dashboard' => true]);

        $student = User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'assigned_groups' => [],
        ]);
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'event-'.$secondId,
                'featured_on_dashboard' => true,
            ]);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => [],
        ]);
        Sanctum::actingAs($teacher);
        $this->postJson('/api/v1/learning/calendar', [
            'title' => 'ครูพยายามเปลี่ยนหน้าแรก',
            'event_type' => 'activity',
            'event_date' => '2026-08-26',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'featured_on_dashboard' => true,
        ])->assertForbidden();
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

    public function test_legacy_student_audience_label_is_visible_to_every_group_in_the_district(): void
    {
        Storage::fake('local');
        $creator = User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->district->id,
        ]);
        $eventId = (int) DB::table('learning_calendar_events')->insertGetId([
            'district_id' => $this->district->id,
            'created_by' => $creator->id,
            'title' => 'กิจกรรมสำหรับนักศึกษาทุกคน',
            'event_type' => 'activity',
            'starts_at' => '2026-08-26 09:00:00',
            'ends_at' => '2026-08-26 12:00:00',
            'target_type' => 'group',
            'target_value' => 'นักศึกษา',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $imagePath = "learning/calendar/{$this->district->id}/{$eventId}/activity.jpg";
        DB::table('learning_calendar_events')->where('id', $eventId)->update(['image_path' => $imagePath]);
        Storage::disk('local')->put($imagePath, 'image-data');
        DB::table('learning_resources')->insert([
            'district_id' => $this->district->id,
            'uploaded_by' => $creator->id,
            'title' => 'สื่อสำหรับนักศึกษาทุกคน',
            'resource_type' => 'link',
            'external_url' => 'https://example.test/resource',
            'visibility' => 'district',
            'target_group' => 'นักศึกษา',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'assigned_groups' => ['GROUP-NOT-IN-CATALOG'],
        ]));

        $this->getJson('/api/v1/learning/calendar')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'event-'.$eventId)
            ->assertJsonPath('data.0.image_url', fn (string $url): bool => str_contains($url, "/calendar/{$eventId}/image"));
        $this->get("/api/v1/learning/calendar/{$eventId}/image")->assertOk();
        $this->getJson('/api/v1/learning/resources')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'สื่อสำหรับนักศึกษาทุกคน');
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
