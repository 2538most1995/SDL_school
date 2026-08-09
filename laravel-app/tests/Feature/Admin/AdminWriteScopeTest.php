<?php

namespace Tests\Feature\Admin;

use App\Models\District;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminWriteScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyDatabase;

    private string $importWorkspace;

    private District $sena;

    private District $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyDatabase = tempnam(sys_get_temp_dir(), 'sena-legacy-test-');
        $connection = ['driver' => 'sqlite', 'database' => $this->legacyDatabase, 'prefix' => '', 'foreign_key_constraints' => true];
        config()->set('database.connections.legacy', $connection);
        config()->set('database.connections.legacy_write', $connection);
        config()->set('legacy.enabled', true);
        config()->set('legacy.write_enabled', true);
        config()->set('legacy.connection', 'legacy');
        config()->set('legacy.write_connection', 'legacy_write');
        $this->importWorkspace = sys_get_temp_dir().'/sena-admin-import-delete-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->importWorkspace.'/zips', 0750, true);
        File::makeDirectory($this->importWorkspace.'/extracted', 0750, true);
        config()->set('legacy.zip_root', $this->importWorkspace.'/zips');
        config()->set('legacy.extract_root', $this->importWorkspace.'/extracted');
        DB::purge('legacy');
        DB::purge('legacy_write');

        $this->sena = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
        $this->other = District::create(['name' => 'อำเภออื่น', 'code' => 'other', 'is_active' => true]);
        $schema = DB::connection('legacy_write')->getSchemaBuilder();
        $schema->create('districts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('role');
            $table->text('assigned_groups')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('exam_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('district_id');
            $table->string('term');
            $table->string('subject_code');
            $table->string('assignment_type');
            $table->string('start_val');
            $table->string('end_val');
            $table->string('room_name');
            $table->timestamp('created_at')->nullable();
        });
        DB::connection('legacy_write')->table('districts')->insert([
            ['id' => $this->sena->id, 'name' => $this->sena->name],
            ['id' => $this->other->id, 'name' => $this->other->name],
        ]);
        DB::connection('legacy_write')->table('users')->insert([
            ['id' => 10, 'username' => 'sena.admin', 'password' => password_hash('password', PASSWORD_BCRYPT), 'first_name' => 'แอดมิน', 'last_name' => 'เสนา', 'role' => 'admin', 'assigned_groups' => '[]', 'district_id' => $this->sena->id],
            ['id' => 20, 'username' => 'other.admin', 'password' => password_hash('password', PASSWORD_BCRYPT), 'first_name' => 'แอดมิน', 'last_name' => 'อื่น', 'role' => 'admin', 'assigned_groups' => '[]', 'district_id' => $this->other->id],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy');
        DB::disconnect('legacy_write');
        if (isset($this->legacyDatabase) && is_file($this->legacyDatabase)) {
            unlink($this->legacyDatabase);
        }
        if (isset($this->importWorkspace)) {
            File::deleteDirectory($this->importWorkspace);
        }
        parent::tearDown();
    }

    public function test_district_admin_can_create_and_edit_only_users_in_own_district(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->sena->id,
            'legacy_user_id' => 10,
        ]));

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
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

        $legacyId = (int) $created->json('data.id');
        $shadow = User::factory()->create([
            'username' => 'sena.teacher',
            'role' => 'teacher',
            'district_id' => $this->sena->id,
            'legacy_key' => "staff:{$legacyId}",
            'legacy_user_id' => $legacyId,
            'assigned_groups' => ['220009'],
        ]);
        $this->patchJson("/api/v1/admin/users/{$legacyId}", [
            'username' => 'sena.teacher',
            'first_name' => 'ครูแก้ไข',
            'last_name' => 'คนใหม่',
            'role' => 'teacher',
            'assigned_groups' => ['220010'],
        ])->assertOk()->assertJsonPath('data.assigned_groups.0', '220010');
        $this->assertSame(['220010'], $shadow->fresh()->assigned_groups);

        $this->patchJson('/api/v1/admin/users/20', [
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
    }

    public function test_user_writes_remain_successful_when_optional_shadow_and_audit_schema_are_missing(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'district_id' => $this->sena->id,
            'legacy_user_id' => 10,
        ]);
        Sanctum::actingAs($admin);
        Schema::drop('audit_logs');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_legacy_key_unique');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('legacy_key');
        });
        Log::spy();

        $this->postJson('/api/v1/admin/users', [
            'username' => 'sena.compat.teacher',
            'password' => 'secure-pass-123',
            'first_name' => 'ครูทดสอบ',
            'last_name' => 'ระบบเดิม',
            'role' => 'teacher',
            'assigned_groups' => ['220010'],
        ])->assertCreated()
            ->assertJsonPath('data.username', 'sena.compat.teacher')
            ->assertJsonPath('data.district_id', $this->sena->id);

        $this->patchJson('/api/v1/admin/users/10', [
            'username' => 'sena.admin',
            'first_name' => 'แอดมินแก้ไข',
            'last_name' => 'เสนา',
            'role' => 'admin',
            'assigned_groups' => ['220009'],
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'แอดมินแก้ไข')
            ->assertJsonPath('data.assigned_groups.0', '220009');

        $this->assertDatabaseHas('users', [
            'id' => 10,
            'first_name' => 'แอดมินแก้ไข',
            'assigned_groups' => '["220009"]',
        ], 'legacy_write');
        $this->assertDatabaseHas('users', [
            'username' => 'sena.compat.teacher',
            'district_id' => $this->sena->id,
            'assigned_groups' => '["220010"]',
        ], 'legacy_write');
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_exam_room_writes_are_scoped_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->sena->id, 'legacy_user_id' => 10]);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/exam-rooms', [
            'term' => '1/2569',
            'subject_code' => 'ทช21001',
            'assignment_type' => 'group_range',
            'start_val' => '1',
            'end_val' => '30',
            'room_name' => 'ห้อง 101',
        ])->assertCreated()->assertJsonPath('data.capacity', 30);

        $roomId = (int) $created->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exam_room.created', 'district_id' => $this->sena->id]);
        $this->deleteJson("/api/v1/admin/exam-rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.exam_room.deleted', 'district_id' => $this->other->id]);
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

    public function test_admin_can_delete_only_the_import_batch_from_own_district(): void
    {
        $ownBatch = 'import_1700000100_aaaa';
        $otherBatch = 'import_1700000101_bbbb';
        $connection = DB::connection('legacy_write');
        $schema = $connection->getSchemaBuilder();
        $schema->create('import_history', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->string('saved_file_name');
            $table->string('batch_key')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('import_batches', function (Blueprint $table): void {
            $table->string('batch_key')->primary();
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('import_history_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        foreach ([[$ownBatch, $this->sena->id], [$otherBatch, $this->other->id]] as [$batch, $districtId]) {
            $historyId = $connection->table('import_history')->insertGetId([
                'file_name' => $batch.'.zip',
                'saved_file_name' => $batch.'.zip',
                'batch_key' => $batch,
                'district_id' => $districtId,
                'status' => 'success',
                'created_at' => now(),
            ]);
            $connection->table('import_batches')->insert([
                'batch_key' => $batch,
                'district_id' => $districtId,
                'import_history_id' => $historyId,
                'created_at' => now(),
            ]);
            $connection->getSchemaBuilder()->create('db_'.$batch.'_1_student', fn (Blueprint $table) => $table->id());
            File::put($this->importWorkspace.'/zips/'.$batch.'.zip', 'zip');
            File::makeDirectory($this->importWorkspace.'/extracted/'.$batch.'/1', 0750, true);
            File::put($this->importWorkspace.'/extracted/'.$batch.'/1/student.dbf', 'dbf');
        }

        $admin = User::factory()->create(['role' => 'admin', 'district_id' => $this->sena->id]);
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/admin/imports/'.$otherBatch)->assertNotFound();
        $this->assertTrue($connection->table('import_batches')->where('batch_key', $otherBatch)->exists());

        $this->deleteJson('/api/v1/admin/imports/'.$ownBatch)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.batch_key', $ownBatch)
            ->assertJsonPath('data.removed_table_count', 1);

        $this->assertFalse($connection->table('import_batches')->where('batch_key', $ownBatch)->exists());
        $this->assertTrue($connection->table('import_batches')->where('batch_key', $otherBatch)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.import.deleted',
            'district_id' => $this->sena->id,
            'user_id' => $admin->id,
        ]);
    }
}
