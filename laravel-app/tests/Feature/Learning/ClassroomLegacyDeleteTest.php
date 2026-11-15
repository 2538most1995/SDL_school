<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use App\Services\Learning\ClassroomLibraryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ClassroomLegacyDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private string $base = '/api/v1/learning/classroom';

    private const COURSE = 'พว31001';

    private const GROUP = 'SENA-M3-A';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $district->id, 'assigned_groups' => [self::GROUP]]);
        $this->actingAs($this->teacher);
        Storage::fake('local');
    }

    private function values(): array
    {
        return ['subject_code' => self::COURSE, 'education_level' => 3];
    }

    private function activity(array $changes = []): int
    {
        return (int) $this->postJson($this->base.'/activities', [...$this->values(), 'title' => 'Old reading', 'type' => 'knowledge',
            'target_group' => self::GROUP, 'content' => '', 'links' => [], 'questions' => [], 'status' => 'published', ...$changes])->assertCreated()->json('data.id');
    }

    private function assignment(): int
    {
        return (int) $this->post('/api/v1/learning/assignments', [...$this->values(), 'title' => 'Old file', 'target_group' => self::GROUP, 'max_score' => 10,
            'status' => 'open', 'due_at' => now()->addDay()->toISOString(), 'material_pdf' => UploadedFile::fake()->create('material.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_delete_removes_only_current_unbound_owned_scope_and_preserves_library_files(): void
    {
        $activity = $this->activity();
        $assignment = $this->assignment();
        $resource = (int) $this->post('/api/v1/learning/resources', ['title' => 'Old media', 'subject' => self::COURSE, 'level' => '3', 'target_group' => self::GROUP,
            'resource_type' => 'pdf', 'file' => UploadedFile::fake()->create('reading.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $originalMaterial = DB::table('learning_assignments')->where('id', $assignment)->value('material_path');
        $originalResource = DB::table('learning_resources')->where('id', $resource)->value('storage_path');
        $source = app(ClassroomLibraryService::class)->listing($this->teacher, $this->teacher->district_id, [], true)['units'][0]->id;
        $template = app(ClassroomLibraryService::class)->save($this->teacher, $this->teacher->district_id, $source);
        $snapshot = json_decode(DB::table('learning_classroom_templates')->where('id', $template)->value('snapshot'), true);
        $kept = [];
        foreach ([['academic_term' => '1/2560'], ['subject_code' => 'OTHER'], ['education_level' => 1], ['target_group' => 'OTHER-GROUP'],
            ['created_by' => $this->teacher->id + 100], ['district_id' => $this->teacher->district_id + 100]] as $changes) {
            $id = $this->activity();
            DB::table('learning_classroom_activities')->where('id', $id)->update($changes);
            $kept[] = $id;
        }
        $unit = $this->postJson($this->base.'/units', [...$this->values(), 'target_group' => self::GROUP, 'title' => 'New chapter', 'status' => 'published'])->assertCreated()->json('data.id');
        $kept[] = $this->activity(['unit_id' => $unit]);
        $generic = $this->activity();
        DB::table('learning_classroom_activities')->where('id', $generic)->update(['target_group' => '', 'audience_groups' => null]);
        $kept[] = $generic;
        DB::table('learning_resources')->insert(['district_id' => $this->teacher->district_id, 'uploaded_by' => $this->teacher->id, 'title' => 'Shared media',
            'subject_code' => null, 'education_level' => null, 'target_group' => self::GROUP, 'resource_type' => 'link', 'external_url' => 'https://example.test/shared']);
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertOk()->assertJsonPath('data.counts.activities', 1)
            ->assertJsonPath('data.counts.assignments', 1)->assertJsonPath('data.counts.resources', 1);
        $this->assertDatabaseMissing('learning_classroom_activities', ['id' => $activity]);
        $this->assertDatabaseMissing('learning_assignments', ['id' => $assignment]);
        $this->assertDatabaseMissing('learning_resources', ['id' => $resource]);
        foreach ($kept as $id) {
            $this->assertDatabaseHas('learning_classroom_activities', ['id' => $id]);
        }
        $this->assertDatabaseHas('learning_resources', ['title' => 'Shared media']);
        Storage::disk('local')->assertMissing($originalMaterial);
        Storage::disk('local')->assertMissing($originalResource);
        Storage::disk('local')->assertExists($snapshot['assignments'][0]['material_path']);
        Storage::disk('local')->assertExists($snapshot['resources'][0]['storage_path']);
    }

    public function test_any_issued_work_or_submission_rejects_entire_delete(): void
    {
        $a = $this->activity();
        $b = $this->activity();
        $assignment = $this->assignment();
        $task = $this->postJson($this->base.'/tasks', [...$this->values(), 'title' => 'Assigned task', 'target_group' => self::GROUP, 'selection_mode' => 'all',
            'due_at' => now()->addDay()->toISOString()])->assertCreated()->json('data.id');
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertUnprocessable();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $a]);
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $b]);
        $this->assertDatabaseHas('learning_assignments', ['id' => $assignment]);
        DB::table('learning_classroom_task_items')->where('task_id', $task)->delete();
        DB::table('learning_classroom_tasks')->where('id', $task)->delete();
        DB::table('learning_submissions')->insert(['assignment_id' => $assignment, 'student_code' => '6650300005', 'submission_type' => 'link', 'external_url' => 'https://example.test/answer', 'status' => 'submitted', 'submitted_at' => now()]);
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertUnprocessable();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $a]);
        $this->assertDatabaseCount('learning_submissions', 1);
        DB::table('learning_submissions')->where('assignment_id', $assignment)->delete();
        DB::table('learning_classroom_submissions')->insert(['activity_id' => $a, 'student_code' => '6650300005', 'student_name' => 'Synthetic learner',
            'answers' => '{}', 'score' => 1, 'max_score' => 1, 'status' => 'reviewed', 'submitted_at' => now()]);
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertUnprocessable();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $b]);
        $this->assertDatabaseHas('learning_assignments', ['id' => $assignment]);
        $this->assertDatabaseHas('learning_classroom_submissions', ['activity_id' => $a, 'score' => 1]);
    }

    public function test_student_read_only_unregistered_and_empty_targets_fail_closed(): void
    {
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertUnprocessable();
        $id = $this->activity();
        $this->postJson($this->base.'/units/legacy/delete', ['subject_code' => 'UNKNOWN', 'education_level' => 3])->assertNotFound();
        config(['system_data.write_enabled' => false]);
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertStatus(503);
        config(['system_data.write_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->teacher->district_id, 'student_code' => '6650300005']));
        $this->postJson($this->base.'/units/legacy/delete', $this->values())->assertForbidden();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $id]);
    }

    public function test_assignment_submission_rechecks_parent_after_initial_workspace_read(): void
    {
        $assignment = $this->assignment();
        $deleted = false;
        DB::listen(function (QueryExecuted $query) use ($assignment, &$deleted): void {
            if (! $deleted && str_starts_with($query->sql, 'select * from "learning_submissions" where "assignment_id" = ? and "student_code" = ?')) {
                $deleted = true;
                DB::table('learning_assignments')->where('id', $assignment)->delete();
            }
        });
        $this->actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->teacher->district_id, 'student_code' => '6650300005']));
        $this->postJson('/api/v1/learning/assignments/'.$assignment.'/submit', ['submission_type' => 'link', 'url' => 'https://example.test/answer'])->assertUnprocessable();
        $this->assertTrue($deleted);
        $this->assertDatabaseCount('learning_submissions', 0);
    }
}
