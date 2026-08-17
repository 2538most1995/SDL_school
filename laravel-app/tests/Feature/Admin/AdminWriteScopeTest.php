<?php

namespace Tests\Feature\Admin;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminWriteScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $importWorkspace;

    private District $sena;

    private District $other;

    private User $senaAdmin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('system_data.enabled', true);
        config()->set('system_data.write_enabled', true);

        $this->importWorkspace = sys_get_temp_dir().'/sena-system-import-delete-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->importWorkspace.'/zips', 0750, true);
        File::makeDirectory($this->importWorkspace.'/extracted', 0750, true);
        config()->set('system_data.zip_root', $this->importWorkspace.'/zips');
        config()->set('system_data.extract_root', $this->importWorkspace.'/extracted');

        $this->sena = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
        $this->other = District::create(['name' => 'อำเภออื่น', 'code' => 'other', 'is_active' => true]);
        $this->senaAdmin = User::factory()->create([
            'name' => 'แอดมิน เสนา',
            'first_name' => 'แอดมิน',
            'last_name' => 'เสนา',
            'username' => 'sena.admin',
            'role' => 'admin',
            'district_id' => $this->sena->id,
            'assigned_groups' => [],
        ]);
        $this->otherAdmin = User::factory()->create([
            'name' => 'แอดมิน อื่น',
            'first_name' => 'แอดมิน',
            'last_name' => 'อื่น',
            'username' => 'other.admin',
            'role' => 'admin',
            'district_id' => $this->other->id,
            'assigned_groups' => [],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->importWorkspace)) {
            File::deleteDirectory($this->importWorkspace);
        }
        parent::tearDown();
    }

    public function test_district_admin_can_create_and_edit_only_users_in_own_district(): void
    {
        Http::preventStrayRequests();
        Sanctum::actingAs($this->senaAdmin);

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.source', 'system_database')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'sena.admin');

        $created = $this->postJson('/api/v1/admin/users', [
            'username' => 'sena.teacher',
            'password' => 'secure-pass-123',
            'first_name' => 'ครู',
            'last_name' => 'คนใหม่',
            'role' => 'teacher',
            'district_id' => $this->other->id,
            'assigned_groups' => ['220009'],
        ])->assertCreated()->assertJsonPath('data.district_id', $this->sena->id);

        $userId = (int) $created->json('data.id');
        $this->patchJson("/api/v1/admin/users/{$userId}", [
            'username' => 'sena.teacher',
            'first_name' => 'ครูแก้ไข',
            'last_name' => 'คนใหม่',
            'role' => 'teacher',
            'assigned_groups' => ['220010'],
        ])->assertOk()->assertJsonPath('data.assigned_groups.0', '220010');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'name' => 'ครูแก้ไข คนใหม่',
            'district_id' => $this->sena->id,
            'auth_source' => 'local',
        ]);

        $this->patchJson("/api/v1/admin/users/{$this->otherAdmin->id}", [
            'username' => 'other.admin',
            'first_name' => 'ห้าม',
            'last_name' => 'แก้',
            'role' => 'admin',
        ])->assertNotFound();

        $this->postJson('/api/v1/admin/users', [
            'username' => 'forbidden.super',
            'password' => 'secure-pass-123',
            'first_name' => 'ห้าม',
            'last_name' => 'สร้าง',
            'role' => 'super_admin',
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_exam_room_writes_are_scoped_and_audited_in_system_database(): void
    {
        $this->bindStudents([
            $this->studentForTerm($this->sena, '6650100001', 2, '15', 'กศน.ตำบลเสนา', '1/2569'),
        ]);
        Sanctum::actingAs($this->senaAdmin);
        $created = $this->postJson('/api/v1/admin/exam-rooms', [
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้อง 101',
        ])->assertCreated()->assertJsonPath('data.capacity', 30);

        $roomId = (int) $created->json('data.id');
        $this->assertDatabaseHas('exam_rooms', ['id' => $roomId, 'district_id' => $this->sena->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.exam_room.created',
            'auditable_type' => 'system_exam_room',
        ]);

        $this->patchJson("/api/v1/admin/exam-rooms/{$roomId}", [
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้อง 102',
        ])->assertOk()->assertJsonPath('data.room_name', 'ห้อง 102');

        $this->deleteJson("/api/v1/admin/exam-rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_exam_room_page_uses_only_current_term_and_exposes_group_and_level_scopes(): void
    {
        $this->bindStudents([
            $this->studentForTerm($this->sena, '6650100001', 2, '15', 'กศน.ตำบลเสนา', '1/2569'),
            $this->studentForTerm($this->sena, '6750100002', 3, '40', 'กศน.ตำบลบ้านแพน', '1/2569'),
            $this->studentForTerm($this->sena, '6550100003', 1, '60', 'กศน.ตำบลเจ้าเจ็ด', '2/2568'),
        ]);
        $currentRoom = DB::table('exam_rooms')->insertGetId([
            'district_id' => $this->sena->id,
            'term' => '69/1',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้องปัจจุบัน',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $historicalRoom = DB::table('exam_rooms')->insertGetId([
            'district_id' => $this->sena->id,
            'term' => '2/2568',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้องย้อนหลัง',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->senaAdmin);
        $this->getJson('/api/v1/admin/exam-rooms')
            ->assertOk()
            ->assertJsonPath('meta.current_term', '1/2569')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $currentRoom)
            ->assertJsonPath('data.0.education_levels.0', 2)
            ->assertJsonPath('data.0.groups.0', '15')
            ->assertJsonFragment(['value' => '15', 'label' => 'กศน.ตำบลเสนา'])
            ->assertJsonFragment(['value' => '40', 'label' => 'กศน.ตำบลบ้านแพน'])
            ->assertJsonMissing(['room_name' => 'ห้องย้อนหลัง']);

        $payload = [
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้ามแก้ย้อนหลัง',
        ];
        $this->patchJson("/api/v1/admin/exam-rooms/{$historicalRoom}", $payload)->assertNotFound();
        $this->postJson('/api/v1/admin/exam-rooms', [...$payload, 'term' => '2/2568'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term');
        $this->postJson('/api/v1/admin/exam-rooms', [
            ...$payload,
            'assignment_type' => 'student_range',
            'start_val' => '12141200006821000068',
            'end_val' => '12141200006821000095',
            'room_name' => 'ห้องรหัสยาว',
        ])->assertCreated()->assertJsonPath('data.capacity', 28);
    }

    public function test_exam_rooms_can_be_synced_from_the_current_exam_schedule_instead_of_history(): void
    {
        $studentCode = '12141200006950100001';
        $this->bindStudents([
            $this->studentForTerm($this->sena, $studentCode, 2, '15', 'กศน.ตำบลเสนา', '1/2569'),
        ]);
        $batch = 'import_1700000300_sync';
        $historyId = DB::table('import_history')->insertGetId([
            'file_name' => 'current.zip',
            'saved_file_name' => 'current.zip',
            'batch_key' => $batch,
            'file_size_kb' => 1,
            'level' => 'ทุกระดับ',
            'file_count' => 2,
            'district_id' => $this->sena->id,
            'status' => 'success',
            'created_at' => now(),
        ]);
        DB::table('import_batches')->insert([
            'batch_key' => $batch,
            'district_id' => $this->sena->id,
            'import_history_id' => $historyId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scheduleTable = 'db_'.$batch.'_2_schedule';
        Schema::create($scheduleTable, function (Blueprint $table): void {
            $table->id();
            $table->string('sub_code');
            $table->string('semestry');
            $table->string('_perf_sub')->index();
            $table->string('_perf_semestry')->index();
        });
        DB::table($scheduleTable)->insert([
            ['sub_code' => 'ทช21001', 'semestry' => '69/1', '_perf_sub' => 'ทช21001', '_perf_semestry' => '69/1'],
            ['sub_code' => 'คณ21001', 'semestry' => '1/2569', '_perf_sub' => 'คณ21001', '_perf_semestry' => '1/2569'],
            ['sub_code' => 'เก่า10001', 'semestry' => '68/2', '_perf_sub' => 'เก่า10001', '_perf_semestry' => '68/2'],
        ]);
        $gradeTable = 'db_'.$batch.'_2_grade';
        Schema::create($gradeTable, function (Blueprint $table): void {
            $table->id();
            $table->string('std_code');
            $table->string('sub_code');
            $table->string('semestry');
            $table->string('roomno')->nullable();
            $table->string('_perf_std10')->index();
            $table->string('_perf_sub')->index();
            $table->string('_perf_semestry')->index();
        });
        DB::table($gradeTable)->insert([
            ['std_code' => '6950100001', 'sub_code' => 'ทช21001', 'semestry' => '69/1', 'roomno' => '008', '_perf_std10' => '6950100001', '_perf_sub' => 'ทช21001', '_perf_semestry' => '69/1'],
            ['std_code' => '6950100001', 'sub_code' => 'คณ21001', 'semestry' => '69/1', 'roomno' => null, '_perf_std10' => '6950100001', '_perf_sub' => 'คณ21001', '_perf_semestry' => '69/1'],
            ['std_code' => '6950100001', 'sub_code' => 'เก่า10001', 'semestry' => '68/2', 'roomno' => '099', '_perf_std10' => '6950100001', '_perf_sub' => 'เก่า10001', '_perf_semestry' => '68/2'],
            ['std_code' => '6950199999', 'sub_code' => 'ทช21001', 'semestry' => '69/1', 'roomno' => '077', '_perf_std10' => '6950199999', '_perf_sub' => 'ทช21001', '_perf_semestry' => '69/1'],
        ]);
        DB::table('exam_rooms')->insert([
            'district_id' => $this->sena->id,
            'term' => '2/2568',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'student_range',
            'start_val' => $studentCode,
            'end_val' => $studentCode,
            'room_name' => 'ห้องเก่าที่ห้ามนำมาใช้',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('exam_rooms')->insert([
            'district_id' => $this->other->id,
            'term' => '2/2568',
            'subject_code' => 'ห้ามข้ามอำเภอ',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้องอำเภออื่น',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Production can contain an adopted legacy exam_rooms table without
        // Laravel timestamp columns. Schedule sync must remain compatible.
        Schema::table('exam_rooms', function (Blueprint $table): void {
            $table->dropColumn(['created_at', 'updated_at']);
        });

        Sanctum::actingAs($this->senaAdmin);
        $this->getJson('/api/v1/admin/exam-rooms')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_term', '1/2569')
            ->assertJsonPath('meta.schedule_sync.available', true)
            ->assertJsonPath('meta.schedule_sync.term', '1/2569')
            ->assertJsonPath('meta.schedule_sync.count', 2);

        $this->postJson('/api/v1/admin/exam-rooms/sync-from-schedule')
            ->assertCreated()
            ->assertJsonPath('data.synced', 2)
            ->assertJsonPath('data.source', 'current_exam_schedule')
            ->assertJsonPath('data.current_term', '1/2569');

        $this->assertDatabaseCount('exam_rooms', 4);
        $this->assertDatabaseHas('exam_rooms', [
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'start_val' => $studentCode,
            'end_val' => $studentCode,
            'room_name' => 'ห้อง 8',
            'import_batch_id' => null,
        ]);
        $this->assertDatabaseHas('exam_rooms', [
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'subject_code' => 'คณ21001',
            'room_name' => 'ห้อง 1',
        ]);
        $this->assertDatabaseMissing('exam_rooms', [
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'room_name' => 'ห้องเก่าที่ห้ามนำมาใช้',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->senaAdmin->id,
            'district_id' => $this->sena->id,
            'event' => 'admin.exam_rooms.synced_from_schedule',
            'auditable_type' => 'system_exam_room',
        ]);
        $this->getJson('/api/v1/admin/exam-rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'subject_code' => 'ทช21001',
                'capacity' => 1,
                'education_levels' => [2],
                'groups' => ['15'],
            ])
            ->assertJsonPath('meta.schedule_sync.available', false);
        $this->postJson('/api/v1/admin/exam-rooms/carry-forward')->assertConflict();
        $this->assertDatabaseCount('exam_rooms', 4);
    }

    public function test_exam_room_page_handles_more_than_two_thousand_exact_current_term_assignments(): void
    {
        $students = [];
        $rooms = [];
        $now = now();
        foreach (range(1, 2250) as $index) {
            $studentCode = sprintf('69501%08d', $index);
            $students[] = $this->studentForTerm($this->sena, $studentCode, 2, '15', 'กศน.ตำบลเสนา', '1/2569');
            $rooms[] = [
                'district_id' => $this->sena->id,
                'term' => '1/2569',
                'subject_code' => 'ทช21001',
                'assignment_type' => 'student_range',
                'start_val' => $studentCode,
                'end_val' => $studentCode,
                'room_name' => 'ห้อง '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->bindStudents($students);
        foreach (array_chunk($rooms, 500) as $chunk) {
            DB::table('exam_rooms')->insert($chunk);
        }

        Sanctum::actingAs($this->senaAdmin);
        $this->getJson('/api/v1/admin/exam-rooms')
            ->assertOk()
            ->assertJsonCount(2250, 'data')
            ->assertJsonPath('data.0.capacity', 1)
            ->assertJsonPath('data.0.education_levels.0', 2)
            ->assertJsonPath('data.0.groups.0', '15');
    }

    public function test_teacher_can_update_only_exam_room_names_for_assigned_students(): void
    {
        $ownStudent = '6911000001';
        $otherStudent = '6911000002';
        $this->bindStudents([
            $this->studentForTerm($this->sena, $ownStudent, 2, '15', 'กลุ่มเรียนเสนา 15', '1/2569'),
            $this->studentForTerm($this->sena, $otherStudent, 3, '40', 'กลุ่มเรียนบ้านแพน 40', '1/2569'),
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->sena->id,
            'assigned_groups' => ['15'],
        ]);
        $ownRoom = DB::table('exam_rooms')->insertGetId([
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'student_range',
            'start_val' => $ownStudent,
            'end_val' => $ownStudent,
            'room_name' => 'ห้องเดิมของครู',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherRoom = DB::table('exam_rooms')->insertGetId([
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'subject_code' => 'พว31001',
            'assignment_type' => 'student_range',
            'start_val' => $otherStudent,
            'end_val' => $otherStudent,
            'room_name' => 'ห้องของกลุ่มอื่น',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sharedRoom = DB::table('exam_rooms')->insertGetId([
            'district_id' => $this->sena->id,
            'term' => '1/2569',
            'subject_code' => 'สค21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '99',
            'room_name' => 'ห้องรวมหลายกลุ่ม',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/admin/exam-rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownRoom)
            ->assertJsonPath('data.0.groups.0', '15')
            ->assertJsonPath('meta.groups.0.value', '15')
            ->assertJsonPath('meta.teacher_scoped', true)
            ->assertJsonPath('meta.permissions.can_create', false)
            ->assertJsonPath('meta.permissions.can_update', true)
            ->assertJsonPath('meta.permissions.can_delete', false)
            ->assertJsonPath('meta.permissions.can_sync', false);

        $attemptedChanges = [
            'term' => '1/2569',
            'subject_code' => 'เปลี่ยนไม่ได้',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '999',
            'room_name' => 'ห้องที่ครูแก้ไข',
        ];
        $this->patchJson("/api/v1/admin/exam-rooms/{$ownRoom}", $attemptedChanges)
            ->assertOk()
            ->assertJsonPath('data.room_name', 'ห้องที่ครูแก้ไข')
            ->assertJsonPath('data.subject_code', 'ทช21001')
            ->assertJsonPath('data.assignment_type', 'student_range')
            ->assertJsonPath('data.start_val', $ownStudent)
            ->assertJsonPath('data.end_val', $ownStudent);
        $this->assertDatabaseHas('exam_rooms', [
            'id' => $ownRoom,
            'subject_code' => 'ทช21001',
            'assignment_type' => 'student_range',
            'start_val' => $ownStudent,
            'end_val' => $ownStudent,
            'room_name' => 'ห้องที่ครูแก้ไข',
        ]);
        $this->patchJson("/api/v1/admin/exam-rooms/{$otherRoom}", $attemptedChanges)->assertForbidden();
        $this->patchJson("/api/v1/admin/exam-rooms/{$sharedRoom}", $attemptedChanges)->assertForbidden();
        $this->postJson('/api/v1/admin/exam-rooms', $attemptedChanges)->assertForbidden();
        $this->postJson('/api/v1/admin/exam-rooms/sync-from-schedule')->assertForbidden();
        $this->deleteJson("/api/v1/admin/exam-rooms/{$ownRoom}")->assertForbidden();
        $this->assertDatabaseHas('exam_rooms', ['id' => $otherRoom, 'room_name' => 'ห้องของกลุ่มอื่น']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'event' => 'admin.exam_room.updated',
            'auditable_id' => $ownRoom,
        ]);
    }

    public function test_super_admin_can_manage_the_explicitly_selected_district(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->withHeader('X-District-Id', (string) $this->other->id)->postJson('/api/v1/admin/users', [
            'username' => 'other.teacher',
            'password' => 'secure-pass-123',
            'first_name' => 'ครู',
            'last_name' => 'อำเภออื่น',
            'role' => 'teacher',
            'district_id' => $this->other->id,
            'assigned_groups' => [],
        ])->assertCreated()->assertJsonPath('data.district_id', $this->other->id);

        $this->withHeader('X-District-Id', (string) $this->other->id)->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment(['username' => 'other.teacher'])
            ->assertJsonMissing(['username' => 'sena.admin']);
    }

    /** @param list<Student> $students */
    private function bindStudents(array $students): void
    {
        $repository = $this->createMock(StudentRepository::class);
        $repository->method('students')->willReturn($students);
        $this->app->instance(StudentRepository::class, $repository);
    }

    private function studentForTerm(
        District $district,
        string $code,
        int $level,
        string $groupCode,
        string $groupName,
        string $term,
    ): Student {
        return new Student(
            code: $code,
            districtId: (int) $district->id,
            districtName: (string) $district->name,
            prefix: 'นาย',
            firstName: 'ผู้เรียน',
            lastName: $code,
            level: $level,
            levelLabel: match ($level) {
                1 => 'ประถมศึกษา',
                2 => 'มัธยมศึกษาตอนต้น',
                default => 'มัธยมศึกษาตอนปลาย',
            },
            groupCode: $groupCode,
            groupName: $groupName,
            enrollmentTerm: $term,
            currentTerm: $term,
            status: 'active',
            statusLabel: 'กำลังศึกษา',
            gpax: 0,
            creditsEarned: 0,
            creditsRequired: 0,
            kpchHours: 0,
            moralResult: '',
        );
    }

    public function test_admin_can_delete_only_import_batch_from_own_system_database_scope(): void
    {
        $ownBatch = 'import_1700000100_aaaa';
        $otherBatch = 'import_1700000101_bbbb';

        foreach ([[$ownBatch, $this->sena->id], [$otherBatch, $this->other->id]] as [$batch, $districtId]) {
            $historyId = DB::table('import_history')->insertGetId([
                'file_name' => $batch.'.zip',
                'saved_file_name' => $batch.'.zip',
                'batch_key' => $batch,
                'file_size_kb' => 1,
                'level' => 'ทุกระดับ',
                'file_count' => 1,
                'district_id' => $districtId,
                'status' => 'success',
                'created_at' => now(),
            ]);
            DB::table('import_batches')->insert([
                'batch_key' => $batch,
                'district_id' => $districtId,
                'import_history_id' => $historyId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Schema::create('db_'.$batch.'_1_student', fn (Blueprint $table) => $table->id());
            File::put($this->importWorkspace.'/zips/'.$batch.'.zip', 'zip');
            File::makeDirectory($this->importWorkspace.'/extracted/'.$batch.'/1', 0750, true);
            File::put($this->importWorkspace.'/extracted/'.$batch.'/1/student.dbf', 'dbf');
        }

        Sanctum::actingAs($this->senaAdmin);

        $this->deleteJson('/api/v1/admin/imports/'.$otherBatch)->assertNotFound();
        $this->assertDatabaseHas('import_batches', ['batch_key' => $otherBatch]);

        $this->deleteJson('/api/v1/admin/imports/'.$ownBatch)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.batch_key', $ownBatch)
            ->assertJsonPath('data.removed_table_count', 1);

        $this->assertDatabaseMissing('import_batches', ['batch_key' => $ownBatch]);
        $this->assertDatabaseHas('import_batches', ['batch_key' => $otherBatch]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.import.deleted',
            'auditable_type' => 'system_import_batch',
            'district_id' => $this->sena->id,
            'user_id' => $this->senaAdmin->id,
        ]);
    }

    public function test_application_has_no_old_database_connections(): void
    {
        $this->assertNull(config('database.connections.legacy'));
        $this->assertNull(config('database.connections.legacy_write'));
        $this->assertArrayNotHasKey('connection', config('system_data'));
        $this->assertArrayNotHasKey('write_connection', config('system_data'));
    }
}
