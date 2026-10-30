<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use App\Services\Learning\ClassroomUnitService;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClassroomUnitContentTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private const COURSE = 'พว31001';

    private const GROUP = 'SENA-M3-A';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => [self::GROUP]]);
        Sanctum::actingAs($this->teacher);
        Storage::fake('local');
    }

    private function unit(string $title, string $status = 'published'): int
    {
        return app(ClassroomUnitService::class)->save($this->teacher, $this->district->id, ['title' => $title, 'description' => '', 'subject_code' => self::COURSE,
            'education_level' => 3, 'target_group' => self::GROUP, 'status' => $status]);
    }

    private function fileValues(array $extra = []): array
    {
        return [...['title' => 'File worksheet', 'subject_code' => self::COURSE, 'education_level' => 3, 'target_group' => self::GROUP,
            'max_score' => 10, 'status' => 'open', 'due_at' => now()->addDay()->toISOString()], ...$extra];
    }

    private function file(?int $unit = null): int
    {
        return (int) $this->postJson('/api/v1/learning/assignments', $this->fileValues($unit === null ? [] : ['unit_id' => $unit]))->assertCreated()->json('data.id');
    }

    private function resource(?int $unit = null): int
    {
        return (int) $this->postJson('/api/v1/learning/resources', ['title' => 'Reading link', 'subject' => self::COURSE, 'level' => '3',
            'resource_type' => 'link', 'url' => 'https://example.test/reading', 'target_group' => self::GROUP, ...($unit === null ? [] : ['unit_id' => $unit])])
            ->assertCreated()->json('data.id');
    }

    private function courseQuery(): string
    {
        return 'subject_code='.urlencode(self::COURSE).'&education_level=3';
    }

    public function test_files_and_resources_filter_exact_unit_and_omitted_update_keeps_binding(): void
    {
        $a = $this->unit('Activity A');
        $b = $this->unit('Activity B');
        $fileA = $this->file($a);
        $this->file($b);
        $legacy = $this->file();
        $resourceA = $this->resource($a);
        $this->resource($b);
        $this->resource();
        $this->getJson('/api/v1/learning/assignments?'.$this->courseQuery().'&unit_id='.$a)->assertOk()->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', (string) $fileA)->assertJsonPath('data.assignments.0.unit_id', (string) $a);
        $this->getJson('/api/v1/learning/assignments?'.$this->courseQuery().'&unit_id=legacy')->assertOk()->assertJsonCount(1, 'data.assignments')->assertJsonPath('data.assignments.0.id', (string) $legacy);
        $this->getJson('/api/v1/learning/resources?'.$this->courseQuery().'&unit_id='.$a)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.raw.unit_id', (string) $a);
        $this->getJson('/api/v1/learning/resources?'.$this->courseQuery().'&unit_id=legacy')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.raw.unit_id', null);
        $this->patchJson('/api/v1/learning/assignments/'.$fileA, $this->fileValues(['title' => 'Updated file']))->assertOk();
        $this->patchJson('/api/v1/learning/resources/'.$resourceA, ['title' => 'Updated link', 'subject' => self::COURSE, 'level' => '3',
            'resource_type' => 'link', 'url' => 'https://example.test/updated', 'target_group' => self::GROUP])->assertOk();
        $this->assertDatabaseHas('learning_assignments', ['id' => $fileA, 'classroom_unit_id' => $a]);
        $this->assertDatabaseHas('learning_resources', ['id' => $resourceA, 'classroom_unit_id' => $a]);
        $this->postJson('/api/v1/learning/assignments', $this->fileValues(['unit_id' => $a, 'subject_code' => 'อช31001']))->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->teacher->update(['assigned_groups' => [self::GROUP, 'SENA-M3-B']]);
        $this->postJson('/api/v1/learning/assignments', $this->fileValues(['unit_id' => $a, 'target_group' => 'SENA-M3-B']))->assertUnprocessable();
        $this->postJson('/api/v1/learning/resources', ['title' => 'Foreign group', 'subject' => self::COURSE, 'level' => '3',
            'resource_type' => 'link', 'url' => 'https://example.test/foreign', 'target_group' => 'SENA-M3-B', 'unit_id' => $a])->assertUnprocessable();
    }

    public function test_tasks_snapshot_only_selected_unit_and_cannot_include_other_unit_work(): void
    {
        $a = $this->unit('Activity A');
        $b = $this->unit('Activity B');
        $fileA = $this->file($a);
        $fileB = $this->file($b);
        $this->file();
        $options = $this->getJson('/api/v1/learning/classroom/tasks/options?'.$this->courseQuery().'&target_group='.self::GROUP.'&unit_id='.$a)
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', (string) $fileA)->assertJsonPath('data.items.0.unit_id', (string) $a);
        $values = ['title' => 'Scoped task', 'subject_code' => self::COURSE, 'education_level' => 3, 'target_group' => self::GROUP,
            'unit_id' => $a, 'selection_mode' => 'selected', 'activity_ids' => [], 'assignment_ids' => [$fileB], 'due_at' => now()->addDays(2)->toISOString()];
        $this->postJson('/api/v1/learning/classroom/tasks', $values)->assertUnprocessable();
        $created = $this->postJson('/api/v1/learning/classroom/tasks', [...$values, 'selection_mode' => 'all', 'assignment_ids' => []])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('learning_classroom_tasks', ['id' => $created, 'classroom_unit_id' => $a]);
        $this->getJson('/api/v1/learning/classroom/tasks?'.$this->courseQuery().'&unit_id='.$a)->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.unit_id', (string) $a);
        $this->getJson('/api/v1/learning/classroom/tasks?'.$this->courseQuery().'&unit_id='.$b)->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson('/api/v1/learning/classroom/tasks/'.$created)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.unit_id', (string) $a);
        $draft = $this->unit('Draft activity', 'draft');
        $this->file($draft);
        $this->getJson('/api/v1/learning/classroom/tasks/options?'.$this->courseQuery().'&target_group='.self::GROUP.'&unit_id='.$draft)->assertUnprocessable();
        $this->getJson('/api/v1/learning/classroom/tasks/options?'.$this->courseQuery().'&target_group='.self::GROUP)->assertOk()->assertJsonCount(3, 'data.items');
    }

    public function test_draft_parent_blocks_student_file_material_resource_and_task_direct_paths(): void
    {
        $unit = $this->unit('Private activity');
        $assignment = $this->post('/api/v1/learning/assignments', $this->fileValues(['unit_id' => $unit, 'material_pdf' => UploadedFile::fake()->create('material.pdf', 10, 'application/pdf')]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $resource = $this->post('/api/v1/learning/resources', ['title' => 'Private PDF', 'subject' => self::COURSE, 'level' => '3', 'resource_type' => 'pdf',
            'target_group' => self::GROUP, 'unit_id' => $unit, 'file' => UploadedFile::fake()->create('reading.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $student = User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '6650300005']);
        Sanctum::actingAs($student);
        $submission = $this->post('/api/v1/learning/assignments/'.$assignment.'/submit', ['submission_type' => 'pdf', 'file' => UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->json('data.id');
        $this->get('/api/v1/learning/resources/'.$resource.'/file')->assertOk();
        Sanctum::actingAs($this->teacher);
        app(ClassroomUnitService::class)->save($this->teacher, $this->district->id, ['status' => 'draft'], $unit);
        Sanctum::actingAs($student);
        $this->assertSame([], app(LegacyPortalReadService::class)->assignments($student, $this->district->id));
        $this->getJson('/api/v1/learning/assignments?'.$this->courseQuery())->assertOk()->assertJsonCount(0, 'data.assignments');
        $this->getJson('/api/v1/learning/resources?'.$this->courseQuery())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/learning/assignments?assignment_id='.$assignment)->assertNotFound();
        $this->get('/api/v1/learning/assignments/'.$assignment.'/material')->assertNotFound();
        $this->get('/api/v1/learning/assignments/'.$assignment.'/submissions/'.$submission.'/file')->assertNotFound();
        $this->get('/api/v1/learning/resources/'.$resource.'/file')->assertNotFound();
        $this->postJson('/api/v1/learning/assignments/'.$assignment.'/submit', ['submission_type' => 'link', 'url' => 'https://example.test/answer'])->assertNotFound();
    }

    public function test_student_cannot_read_stale_task_when_parent_is_no_longer_published(): void
    {
        $unit = $this->unit('Published activity');
        $this->file($unit);
        $task = $this->postJson('/api/v1/learning/classroom/tasks', ['title' => 'Task under parent', 'subject_code' => self::COURSE,
            'education_level' => 3, 'target_group' => self::GROUP, 'unit_id' => $unit, 'selection_mode' => 'all',
            'due_at' => now()->addDays(2)->toISOString()])->assertCreated()->json('data.id');
        // Simulate a pre-existing inconsistent record; the public API prevents this state transition.
        DB::table('learning_classroom_units')->where('id', $unit)->update(['status' => 'draft']);
        $student = User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '6650300005']);
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/learning/classroom/tasks?'.$this->courseQuery())->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson('/api/v1/learning/classroom/tasks/'.$task)->assertNotFound();
    }
}
