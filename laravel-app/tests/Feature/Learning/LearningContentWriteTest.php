<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LearningContentWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyDatabase;
    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyDatabase = tempnam(sys_get_temp_dir(), 'sena-learning-write-');
        $connection = ['driver' => 'sqlite', 'database' => $this->legacyDatabase, 'prefix' => '', 'foreign_key_constraints' => true];
        config()->set('database.connections.legacy', $connection);
        config()->set('database.connections.legacy_write', $connection);
        config()->set('legacy.enabled', true);
        config()->set('legacy.write_enabled', true);
        config()->set('legacy.connection', 'legacy');
        config()->set('legacy.write_connection', 'legacy_write');
        DB::purge('legacy');
        DB::purge('legacy_write');
        $this->district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
        DB::connection('legacy_write')->getSchemaBuilder()->create('learning_assignments', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('district_id'); $table->string('title'); $table->string('subject');
            $table->text('description')->nullable(); $table->dateTime('due_at'); $table->string('target_group')->nullable();
            $table->string('target_mode'); $table->string('status'); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy'); DB::disconnect('legacy_write');
        if (isset($this->legacyDatabase) && is_file($this->legacyDatabase)) unlink($this->legacyDatabase);
        parent::tearDown();
    }

    public function test_teacher_can_manage_only_owned_content_in_assigned_group(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'legacy_user_id' => 11, 'assigned_groups' => ['G-01']]);
        Sanctum::actingAs($teacher);
        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'ใบงานภาษาไทย', 'subject' => 'พท31001', 'description' => 'บทที่ 1', 'due_at' => '2026-08-01 12:00:00',
            'target_group' => 'G-01', 'target_mode' => 'group', 'status' => 'open',
        ])->assertCreated();
        $id = (int) $created->json('data.id');
        $this->patchJson("/api/v1/learning/assignments/{$id}", [
            'title' => 'ใบงานภาษาไทย (แก้ไข)', 'subject' => 'พท31001', 'description' => '', 'due_at' => '2026-08-02 12:00:00',
            'target_group' => 'G-01', 'target_mode' => 'group', 'status' => 'open',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.assignments.updated', 'district_id' => $this->district->id]);

        DB::connection('legacy_write')->table('learning_assignments')->insert([
            'district_id' => $this->district->id, 'title' => 'ของครูอื่น', 'subject' => 'พท31001', 'due_at' => now(),
            'target_group' => 'G-01', 'target_mode' => 'group', 'status' => 'open', 'created_by' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherId = (int) DB::connection('legacy_write')->table('learning_assignments')->where('created_by', 99)->value('id');
        $this->deleteJson("/api/v1/learning/assignments/{$otherId}")->assertNotFound();
        $this->postJson('/api/v1/learning/assignments', [
            'title' => 'ข้ามกลุ่ม', 'subject' => 'พท31001', 'due_at' => '2026-08-01 12:00:00',
            'target_group' => 'G-99', 'target_mode' => 'group', 'status' => 'open',
        ])->assertForbidden();
    }

    public function test_student_cannot_mutate_learning_content(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id]));
        $this->postJson('/api/v1/learning/assignments', [])->assertForbidden();
    }
}
