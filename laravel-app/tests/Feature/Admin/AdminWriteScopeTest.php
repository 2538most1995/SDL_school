<?php

namespace Tests\Feature\Admin;

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

        $this->deleteJson("/api/v1/admin/exam-rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
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
