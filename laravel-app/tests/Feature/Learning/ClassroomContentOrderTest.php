<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClassroomContentOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private string $base = '/api/v1/learning/classroom';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $district = District::create(['name' => 'เสนา', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $district->id, 'assigned_groups' => ['SENA-M3-A']]);
        Sanctum::actingAs($this->teacher);
    }

    private function activity(string $title): int
    {
        return (int) $this->postJson($this->base.'/activities', ['title' => $title, 'type' => 'knowledge', 'subject_code' => 'พว31001',
            'education_level' => 3, 'target_group' => 'SENA-M3-A', 'content' => '', 'links' => [], 'questions' => [], 'status' => 'published'])
            ->assertCreated()->json('data.id');
    }

    public function test_order_persists_for_teacher_and_student_without_changing_content_or_answers(): void
    {
        $a = $this->activity('First created');
        $b = $this->activity('Second created');
        $c = $this->activity('Last created');
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'before', 'anchor_id' => $c])->assertOk();
        $query = '?subject_code='.urlencode('พว31001').'&education_level=3&unit_id=legacy';
        $response = $this->getJson($this->base.$query)->assertOk();
        $this->assertSame([(string) $a, (string) $c, (string) $b], array_column($response->json('data.activities'), 'id'));
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'after', 'anchor_id' => $b])->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->teacher->district_id, 'student_code' => '6650300005']));
        $response = $this->getJson($this->base.$query)->assertOk();
        $this->assertSame([(string) $c, (string) $b, (string) $a], array_column($response->json('data.activities'), 'id'));
        $this->postJson($this->base.'/activities/'.$c.'/position', ['placement' => 'first'])->assertForbidden();
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $a, 'title' => 'First created', 'status' => 'published']);
    }

    public function test_reorder_rejects_foreign_teacher_group_course_and_read_only_writes(): void
    {
        $a = $this->activity('Mine');
        $foreign = $this->activity('Another teacher');
        DB::table('learning_classroom_activities')->where('id', $foreign)->update(['created_by' => $this->teacher->id + 100]);
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'before', 'anchor_id' => $foreign])->assertForbidden();
        $this->postJson($this->base.'/activities/'.$foreign.'/position', ['placement' => 'first'])->assertForbidden();
        $otherGroup = $this->activity('Other group');
        DB::table('learning_classroom_activities')->where('id', $otherGroup)->update(['target_group' => 'SENA-M3-B']);
        $this->postJson($this->base.'/activities/'.$otherGroup.'/position', ['placement' => 'first'])->assertNotFound();
        $otherDistrict = $this->activity('Other district');
        DB::table('learning_classroom_activities')->where('id', $otherDistrict)->update(['district_id' => $this->teacher->district_id + 100]);
        $this->postJson($this->base.'/activities/'.$otherDistrict.'/position', ['placement' => 'first'])->assertNotFound();
        config(['system_data.write_enabled' => false]);
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'first'])->assertStatus(503);
        $this->assertSame(0, DB::table('learning_classroom_activities')->whereNotNull('sort_order')->count());
    }

    public function test_reorder_rejects_another_chapter_and_preserves_hidden_audiences(): void
    {
        $a = $this->activity('Visible');
        $b = $this->activity('Another audience');
        DB::table('learning_classroom_activities')->where('id', $b)->update(['audience_groups' => json_encode(['SENA-M3-A', 'historic-group'])]);
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'before', 'anchor_id' => $b])->assertUnprocessable();
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'first'])->assertOk();
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $b, 'sort_order' => null]);
        $unit = $this->postJson($this->base.'/units', ['title' => 'Another chapter', 'subject_code' => 'พว31001', 'education_level' => 3,
            'target_group' => 'SENA-M3-A', 'status' => 'published'])->assertCreated()->json('data.id');
        DB::table('learning_classroom_activities')->where('id', $b)->update(['classroom_unit_id' => $unit, 'audience_groups' => json_encode(['SENA-M3-A'])]);
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'after', 'anchor_id' => $b])->assertUnprocessable();
    }

    public function test_moving_to_first_works_across_server_pages_and_migration_is_repeatable(): void
    {
        $a = $this->activity('Oldest');
        $row = (array) DB::table('learning_classroom_activities')->where('id', $a)->first();
        unset($row['id']);
        DB::table('learning_classroom_activities')->insert(array_fill(0, 55, $row));
        $migration = require database_path('migrations/2026_11_15_000044_add_classroom_content_order.php');
        $migration->up();
        $this->postJson($this->base.'/activities/'.$a.'/position', ['placement' => 'first'])->assertOk();
        $this->getJson($this->base.'?subject_code='.urlencode('พว31001').'&education_level=3')->assertOk()
            ->assertJsonPath('data.activities.0.id', (string) $a)->assertJsonPath('data.activity_pagination.total', 56);
    }
}
