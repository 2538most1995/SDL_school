<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClassroomTaskTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private const BASE = '/api/v1/learning/classroom';

    private const COURSE = '?subject_code=%E0%B8%9E%E0%B8%A731001&education_level=3';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'เสนา', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        Sanctum::actingAs($this->teacher);
    }

    private function student(string $code = '6650300005'): User
    {
        return User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => $code]);
    }

    private function activity(string $type = 'quiz', array $overrides = []): int
    {
        return (int) $this->postJson(self::BASE.'/activities', array_replace(['title' => 'กิจกรรม '.$type, 'type' => $type, 'subject_code' => 'พว31001', 'education_level' => 3,
            'target_group' => '', 'content' => 'เนื้อหา', 'links' => $type === 'video' ? [['title' => 'คลิป', 'url' => 'https://example.test/video']] : [],
            'questions' => in_array($type, ['knowledge', 'video']) ? [] : [['id' => 'q1', 'type' => 'single_choice', 'prompt' => 'คำถาม', 'image_id' => null, 'points' => 2, 'options' => ['ก', 'ข'], 'answer' => 'ก']],
            'status' => 'published', 'due_at' => now()->subDay()->toISOString()], $overrides))->assertCreated()->json('data.id');
    }

    private function values(array $overrides = []): array
    {
        return array_replace(['subject_code' => 'พว31001', 'education_level' => 3, 'title' => 'ทบทวนก่อนสอบ', 'instructions' => 'ทำกิจกรรมที่ครูเลือก',
            'due_at' => now()->addDays(2)->toISOString(), 'target_group' => '', 'selection_mode' => 'all', 'activity_ids' => [], 'assignment_ids' => []], $overrides);
    }

    private function task(array $overrides = []): int
    {
        return (int) $this->postJson(self::BASE.'/tasks', $this->values($overrides))->assertCreated()->json('data.id');
    }

    public function test_all_task_snapshots_items_tracks_pending_partial_complete_and_reuses_original_answers(): void
    {
        $read = $this->activity('knowledge');
        $quiz = $this->activity();
        $task = $this->task();
        $this->activity('video'); // Created later; does not silently expand the issued task.
        $this->getJson(self::BASE.'/tasks'.self::COURSE)->assertOk()->assertJsonPath('data.tasks.0.item_count', 2);
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE.'/tasks')->assertOk()->assertJsonPath('data.tasks.0.progress.status', 'pending')->assertJsonPath('data.tasks.0.progress.total', 2);
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonCount(0, 'data.students')->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.completed', false);
        $this->postJson(self::BASE."/tasks/{$task}/activities/{$read}/complete")->assertOk();
        $this->postJson(self::BASE."/tasks/{$task}/activities/{$read}/complete")->assertOk();
        $this->assertDatabaseCount('learning_classroom_completions', 1);
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.progress.status', 'in_progress');
        // Original activity due was yesterday; the issued task's deadline is authoritative.
        $this->postJson(self::BASE."/activities/{$quiz}/submit", ['answers' => ['q1' => 'ก']])->assertOk();
        $this->postJson(self::BASE."/activities/{$quiz}/submit", ['answers' => ['q1' => 'ก']])->assertConflict();
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.progress.status', 'completed')->assertJsonPath('data.progress.completed', 2);
        Sanctum::actingAs($this->teacher);
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.completed_count', 1)->assertJsonPath('data.students.0.student_code', '6650300005')->assertJsonPath('data.students.0.status', 'completed');
        $this->getJson(self::BASE.'/tasks/'.$task.'?status=completed')->assertOk()->assertJsonCount(1, 'data.students')->assertJsonPath('data.pagination.total', 1);
        $this->getJson(self::BASE.'/tasks/'.$task.'?status=pending')->assertOk()->assertJsonCount(0, 'data.students')->assertJsonPath('data.pagination.total', 0);
        $this->getJson(self::BASE.'/tasks/'.$task.'?status=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('learning_classroom_submissions', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.classroom.task_created']);
    }

    public function test_selected_worksheet_can_reuse_file_submission_and_preserve_zero_scores(): void
    {
        $quiz = $this->activity();
        $file = DB::table('learning_assignments')->insertGetId(['district_id' => $this->district->id, 'created_by' => $this->teacher->id,
            'academic_term' => '2/2568', 'subject_code' => 'พว31001', 'subject_name' => 'วิทยาศาสตร์', 'education_level' => null,
            'title' => 'ใบงานไฟล์เดิม', 'instructions' => '', 'target_type' => 'group', 'target_value' => 'SENA-M3-A', 'max_score' => 20,
            'due_at' => now()->addDay(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $task = $this->task(['selection_mode' => 'selected', 'assignment_ids' => [$file]]);
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.kind', 'assignment');
        Sanctum::actingAs($this->student());
        $this->postJson('/api/v1/learning/assignments/'.$file.'/submit', ['submission_type' => 'link', 'url' => 'https://example.test/work'])->assertOk();
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.progress.status', 'completed');
        $this->postJson(self::BASE."/tasks/{$task}/activities/{$quiz}/complete")->assertUnprocessable();
        $this->assertDatabaseCount('learning_submissions', 1);
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
    }

    public function test_scope_and_role_checks_reject_cross_district_groups_unregistered_and_foreign_content(): void
    {
        $quiz = $this->activity();
        $task = $this->task();
        $other = District::create(['name' => 'อื่น', 'code' => 'other', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->getJson(self::BASE."/tasks/{$task}")->assertNotFound();
        $this->postJson(self::BASE.'/tasks', $this->values(['selection_mode' => 'selected', 'activity_ids' => [$quiz]]))->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]));
        $this->getJson(self::BASE."/tasks/{$task}")->assertForbidden();
        $this->postJson(self::BASE.'/tasks', $this->values(['selection_mode' => 'selected', 'activity_ids' => [$quiz]]))->assertUnprocessable();
        Sanctum::actingAs($this->student('unknown'));
        $this->getJson(self::BASE.'/tasks')->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson(self::BASE."/tasks/{$task}")->assertNotFound();
        $this->postJson(self::BASE."/tasks/{$task}/activities/{$quiz}/complete")->assertNotFound();
        Sanctum::actingAs($this->student('6650300007'));
        $this->getJson(self::BASE.'/tasks')->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson(self::BASE."/tasks/{$task}")->assertNotFound();
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE.'/tasks/options'.self::COURSE)->assertForbidden();
        $this->postJson(self::BASE.'/tasks', $this->values())->assertForbidden();
    }

    public function test_validation_and_content_freeze_preserve_issued_tasks(): void
    {
        $quiz = $this->activity();
        $this->postJson(self::BASE.'/tasks', $this->values(['selection_mode' => 'selected']))->assertUnprocessable();
        $this->postJson(self::BASE.'/tasks', $this->values(['due_at' => now()->subMinute()->toISOString()]))->assertUnprocessable();
        $this->postJson(self::BASE.'/tasks', $this->values(['selection_mode' => 'selected', 'activity_ids' => [$quiz, $quiz]]))->assertUnprocessable();
        $this->postJson(self::BASE.'/tasks', $this->values(['target_group' => 'SENA-M3-B']))->assertUnprocessable();
        $this->task();
        $this->getJson(self::BASE.self::COURSE.'&activity_id='.$quiz)->assertOk()->assertJsonPath('data.selected_activity.content_locked', true)->assertJsonPath('data.selected_activity.can_edit', true);
        $this->postJson(self::BASE.'/activities/'.$quiz, ['status' => 'draft'])->assertUnprocessable();
        $this->deleteJson(self::BASE.'/activities/'.$quiz)->assertUnprocessable();
        config(['system_data.write_enabled' => false]);
        $this->postJson(self::BASE.'/tasks', $this->values())->assertServiceUnavailable();
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE.'/tasks')->assertOk()->assertJsonPath('meta.read_only', true);
    }

    public function test_older_assigned_activity_is_reachable_beyond_first_gallery_page(): void
    {
        $old = $this->activity('knowledge');
        for ($i = 0; $i < 51; $i++) {
            $this->activity('knowledge');
        }
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE.self::COURSE.'&activity_id='.$old)->assertOk()->assertJsonPath('data.selected_activity.id', (string) $old)->assertJsonCount(50, 'data.activities');
    }

    public function test_late_read_completion_is_visible_and_deadline_blocks_online_answer(): void
    {
        $read = $this->activity('knowledge');
        $quiz = $this->activity();
        $task = $this->task();
        $this->travel(3)->days();
        Sanctum::actingAs($this->student());
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.progress.overdue', true);
        $this->postJson(self::BASE."/tasks/{$task}/activities/{$read}/complete")->assertOk();
        $this->getJson(self::BASE."/tasks/{$task}")->assertOk()->assertJsonPath('data.progress.late', true);
        $this->postJson(self::BASE."/activities/{$quiz}/submit", ['answers' => ['q1' => 'ก']])->assertUnprocessable();
    }
}
