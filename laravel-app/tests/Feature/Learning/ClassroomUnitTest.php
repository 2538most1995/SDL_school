<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClassroomUnitTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private const BASE = '/api/v1/learning/classroom';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        Sanctum::actingAs($this->teacher);
    }

    private function course(): array
    {
        return ['subject_code' => 'พว31001', 'education_level' => 3];
    }

    private function createUnit(array $values = []): int
    {
        return (int) $this->postJson(self::BASE.'/units', [...$this->course(), 'title' => 'Ecosystem', 'description' => 'Read, watch and try', 'target_group' => 'SENA-M3-A', 'status' => 'published', ...$values])->assertCreated()->json('data.id');
    }

    private function contentValues(?int $unit = null, array $values = []): array
    {
        return [...$this->course(), 'title' => 'Read ecosystems', 'type' => 'knowledge', 'content' => 'Environment', 'target_group' => 'SENA-M3-A', 'status' => 'published', 'links' => [], 'questions' => [], ...($unit === null ? [] : ['unit_id' => $unit]), ...$values];
    }

    private function createContent(?int $unit = null, array $values = []): int
    {
        return (int) $this->postJson(self::BASE.'/activities', $this->contentValues($unit, $values))->assertCreated()->json('data.id');
    }

    private function listing(): string
    {
        return self::BASE.'/units?'.http_build_query($this->course());
    }

    private function workspace(array $filters = []): string
    {
        return self::BASE.'?'.http_build_query([...$this->course(), ...$filters]);
    }

    private function student(string $code = '6650300005'): User
    {
        return User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => $code]);
    }

    public function test_crud_and_legacy_contents_preserve_original_ids(): void
    {
        $legacy = $this->createContent();
        $unit = $this->createUnit();
        $this->getJson($this->listing())->assertOk()->assertJsonCount(2, 'data.units')->assertJsonPath('data.units.0.id', 'legacy')->assertJsonPath('data.units.0.activity_counts.knowledge', 1)->assertJsonPath('data.units.1.can_delete', true);
        $this->patchJson(self::BASE.'/units/'.$unit, ['title' => 'Updated title', 'description' => 'New intro'])->assertOk();
        $this->getJson(self::BASE.'/units/'.$unit)->assertOk()->assertJsonPath('data.title', 'Updated title');
        $this->deleteJson(self::BASE.'/units/'.$unit)->assertOk();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $legacy, 'classroom_unit_id' => null]);
        $this->getJson($this->workspace(['unit_id' => 'legacy']))->assertOk()->assertJsonPath('data.activities.0.id', (string) $legacy);
    }

    public function test_content_filters_and_protected_parent_scope(): void
    {
        $first = $this->createUnit();
        $second = $this->createUnit(['title' => 'Second']);
        $content = $this->createContent($first);
        $this->getJson($this->workspace(['unit_id' => $first]))->assertOk()->assertJsonCount(1, 'data.activities')->assertJsonPath('data.selected_unit.id', (string) $first)->assertJsonPath('data.activities.0.unit_id', (string) $first);
        $this->getJson($this->workspace(['unit_id' => $second]))->assertOk()->assertJsonCount(0, 'data.activities');
        $this->getJson($this->workspace(['unit_id' => $second, 'activity_id' => $content]))->assertNotFound();
        $this->deleteJson(self::BASE.'/units/'.$first)->assertUnprocessable();
        $this->patchJson(self::BASE.'/units/'.$first, ['subject_code' => 'อช31001'])->assertUnprocessable();
        $this->patchJson(self::BASE.'/units/'.$first, ['title' => 'Better title'])->assertOk();
        $this->getJson(self::BASE.'/units/'.$first)->assertOk()->assertJsonPath('data.can_delete', false)->assertJsonPath('data.activity_counts.knowledge', 1);
        $this->postJson(self::BASE.'/activities/'.$content, $this->contentValues(null, ['title' => 'Updated child']))->assertOk();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $content, 'classroom_unit_id' => $first]);
    }

    public function test_student_cannot_bypass_draft_parent_through_direct_child_requests(): void
    {
        $unit = $this->createUnit(['status' => 'draft']);
        $content = $this->createContent($unit, ['type' => 'quiz', 'questions' => [['id' => 'q1', 'type' => 'true_false', 'prompt' => 'True?', 'options' => [], 'image_id' => null, 'points' => 1, 'answer' => 'true']]]);
        Sanctum::actingAs($this->student());
        $this->getJson($this->listing())->assertOk()->assertJsonCount(0, 'data.units');
        $this->getJson($this->workspace())->assertOk()->assertJsonCount(0, 'data.activities');
        $this->getJson($this->workspace(['activity_id' => $content]))->assertNotFound();
        $this->getJson($this->workspace(['unit_id' => $unit]))->assertNotFound();
        $this->postJson(self::BASE.'/activities/'.$content.'/submit', ['answers' => ['q1' => 'true']])->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $this->patchJson(self::BASE.'/units/'.$unit, ['status' => 'published'])->assertOk();
        Sanctum::actingAs($this->student());
        $this->getJson($this->workspace(['unit_id' => $unit, 'activity_id' => $content]))->assertOk()->assertJsonMissingPath('data.selected_activity.questions.0.answer');
        $this->postJson(self::BASE.'/activities/'.$content.'/submit', ['answers' => ['q1' => 'true']])->assertOk();
    }

    public function test_own_group_district_creator_and_course_are_required(): void
    {
        $unit = $this->createUnit();
        foreach (['', 'SENA-M3-B'] as $group) {
            $this->postJson(self::BASE.'/units', [...$this->course(), 'title' => 'Invalid', 'status' => 'published', 'target_group' => $group])->assertUnprocessable();
        }
        $this->postJson(self::BASE.'/activities', $this->contentValues($unit, ['subject_code' => 'อช31001']))->assertUnprocessable();
        foreach ([['SENA-M3-B'], []] as $groups) {
            Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => $groups]));
            $this->getJson(self::BASE.'/units/'.$unit)->assertNotFound();
            $this->patchJson(self::BASE.'/units/'.$unit, ['title' => 'Not mine'])->assertNotFound();
            $this->deleteJson(self::BASE.'/units/'.$unit)->assertNotFound();
        }
        Sanctum::actingAs($this->student('6650300007'));
        $this->getJson(self::BASE.'/units/'.$unit)->assertNotFound();
        $other = District::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson(self::BASE.'/units/'.$unit)->assertNotFound();
    }

    public function test_issued_tasks_freeze_parent_visibility_but_title_and_description_stay_editable(): void
    {
        $unit = $this->createUnit();
        $this->createContent($unit);
        DB::table('learning_classroom_tasks')->insert(['district_id' => $this->district->id, 'created_by' => $this->teacher->id, 'academic_term' => '2/2568',
            'subject_code' => 'พว31001', 'subject_name' => 'Science', 'education_level' => 3, 'target_group' => 'SENA-M3-A', 'audience_groups' => json_encode(['SENA-M3-A']),
            'title' => 'Issued task', 'selection_mode' => 'selected', 'items' => '[]', 'due_at' => now()->addDay(), 'classroom_unit_id' => $unit]);
        $this->patchJson(self::BASE.'/units/'.$unit, ['status' => 'draft'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson(self::BASE.'/units/'.$unit, ['title' => 'Better title', 'description' => 'Better introduction'])->assertOk();
        $this->getJson(self::BASE.'/units/'.$unit)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.title', 'Better title');
    }

    public function test_draft_parent_blocks_private_prompt_image_even_when_child_is_published(): void
    {
        $unit = $this->createUnit(['status' => 'draft']);
        $content = $this->createContent($unit);
        $image = DB::table('learning_classroom_images')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id,
            'activity_id' => $content, 'path' => 'private-test.png', 'mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE.'/images/'.$image)->assertNotFound();
    }

    public function test_explicit_legacy_or_null_units_require_complete_migration_while_omitted_units_remain_compatible(): void
    {
        Schema::table('learning_assignments', function (Blueprint $table): void {
            $table->dropIndex(['classroom_unit_id']);
            $table->dropColumn('classroom_unit_id');
        });
        $file = [...$this->course(), 'title' => 'Worksheet', 'target_group' => 'SENA-M3-A', 'max_score' => 10, 'status' => 'open', 'due_at' => now()->addDay()->toISOString()];
        $resource = ['title' => 'Reading', 'subject' => 'พว31001', 'level' => '3', 'target_group' => 'SENA-M3-A', 'resource_type' => 'link', 'url' => 'https://example.test/reading'];
        foreach (['legacy', null, ''] as $unit) {
            $this->postJson('/api/v1/learning/assignments', [...$file, 'unit_id' => $unit])->assertStatus(503);
            $this->postJson('/api/v1/learning/resources', [...$resource, 'unit_id' => $unit])->assertStatus(503);
            $this->postJson(self::BASE.'/activities', $this->contentValues(null, ['unit_id' => $unit]))->assertStatus(503);
        }
        $this->postJson('/api/v1/learning/assignments', $file)->assertCreated();
        $this->postJson('/api/v1/learning/resources', $resource)->assertCreated();
        $this->postJson(self::BASE.'/activities', $this->contentValues())->assertCreated();
    }
}
