<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use App\Support\StudentDashboardMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OnlineClassroomTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private const BASE = '/api/v1/learning/classroom';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'เสนา', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]);
        Sanctum::actingAs($this->teacher);
    }

    private function student(string $code = '6650300005', ?int $district = null): User
    {
        return User::factory()->create(['role' => 'student', 'district_id' => $district ?? $this->district->id, 'student_code' => $code]);
    }

    private function question(string $id = 'q1', string $type = 'single_choice', mixed $answer = 'ก'): array
    {
        return ['id' => $id, 'type' => $type, 'prompt' => 'คำถามทดสอบ', 'image_id' => null, 'points' => 2,
            'options' => in_array($type, ['single_choice', 'multiple_choice']) ? ['ก', 'ข', 'ค'] : [], 'answer' => $answer];
    }

    private function values(array $overrides = []): array
    {
        return array_replace(['title' => 'แบบทดสอบวิทยาศาสตร์', 'type' => 'quiz', 'subject_code' => 'พว31001', 'education_level' => 3,
            'target_group' => 'SENA-M3-A', 'content' => 'คำชี้แจง', 'links' => [], 'questions' => [$this->question()],
            'status' => 'published', 'due_at' => now()->addDay()->toISOString()], $overrides);
    }

    private function createActivity(array $overrides = []): int
    {
        return (int) $this->postJson(self::BASE.'/activities', $this->values($overrides))->assertCreated()->json('data.id');
    }

    private function selected(int $id): string
    {
        return self::BASE.'?subject_code='.urlencode('พว31001').'&education_level=3&activity_id='.$id;
    }

    public function test_full_quiz_grading_uses_server_keys_and_never_exposes_them_to_students(): void
    {
        $id = $this->createActivity(['questions' => [$this->question('q1'), $this->question('q2', 'multiple_choice', ['ก', 'ค']),
            $this->question('q3', 'true_false', 'false'), $this->question('q4', 'short_answer', 'Science')]]);
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.questions.0.answer', 'ก');
        Sanctum::actingAs($this->student());
        $data = $this->getJson($this->selected($id))->assertOk()->assertJsonCount(0, 'data.submissions')->json('data.selected_activity');
        foreach ($data['questions'] as $question) {
            $this->assertArrayNotHasKey('answer', $question);
        }
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก', 'q2' => ['ค', 'ก'], 'q3' => 'false', 'q4' => ' science '], 'score' => 100])
            ->assertOk()->assertJsonPath('data.score', 8)->assertJsonPath('data.status', 'reviewed');
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.submission.score', 8);
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertConflict();
        $this->assertDatabaseCount('learning_classroom_submissions', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.classroom.submitted']);
    }

    public function test_online_worksheet_accepts_essay_and_teacher_reviews_total_score(): void
    {
        $id = $this->createActivity(['type' => 'worksheet', 'questions' => [$this->question('q1', 'essay', null)]]);
        Sanctum::actingAs($this->student());
        $submission = $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ผลการทดลองของฉัน']])
            ->assertOk()->assertJsonPath('data.score', null)->assertJsonPath('data.status', 'submitted')->json('data.id');
        $this->patchJson(self::BASE."/activities/{$id}/submissions/{$submission}/review", ['score' => 2])->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.submissions.0.answers.q1', 'ผลการทดลองของฉัน');
        $this->patchJson(self::BASE."/activities/{$id}/submissions/{$submission}/review", ['score' => 3])->assertUnprocessable();
        $this->patchJson(self::BASE."/activities/{$id}/submissions/{$submission}/review", ['score' => -1])->assertUnprocessable();
        $this->patchJson(self::BASE."/activities/{$id}/submissions/{$submission}/review", ['score' => 1.5, 'feedback' => 'อธิบายดี'])
            ->assertOk()->assertJsonPath('data.score', 1.5)->assertJsonPath('data.status', 'reviewed');
        $this->postJson(self::BASE."/activities/{$id}", $this->values())->assertUnprocessable();
        $this->deleteJson(self::BASE."/activities/{$id}")->assertUnprocessable();
        Sanctum::actingAs($this->student());
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.submission.feedback', 'อธิบายดี');
        Sanctum::actingAs($this->teacher);
        $this->postJson(self::BASE."/activities/{$id}", ['status' => 'draft'])->assertOk();
    }

    public function test_knowledge_supports_youtube_drive_onedrive_and_plain_content(): void
    {
        $links = [['title' => 'วิดีโอ', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['title' => 'เอกสาร Google', 'url' => 'https://drive.google.com/file/d/example/view'],
            ['title' => 'เอกสาร Microsoft', 'url' => 'https://1drv.ms/u/s!example']];
        $id = $this->createActivity(['type' => 'knowledge', 'content' => 'ใบความรู้วิทยาศาสตร์', 'links' => $links, 'questions' => []]);
        Sanctum::actingAs($this->student());
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.links', $links)->assertJsonPath('data.selected_activity.content', 'ใบความรู้วิทยาศาสตร์');
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertUnprocessable();
    }

    public function test_web_only_registered_courses_are_included_but_unselected_or_transferred_courses_are_excluded(): void
    {
        DB::table('learning_course_registrations')->insert(['district_id' => $this->district->id, 'academic_term' => '2/2568', 'education_level' => 3,
            'student_code' => '6650300005', 'compulsory_subjects' => '[]', 'elective_subjects' => json_encode([
                ['code' => 'WEB001', 'name' => 'วิชาเลือกออนไลน์', 'registered' => true], ['code' => 'NO001', 'registered' => false],
                ['code' => 'TR001', 'registered' => true, 'transferred' => true]])]);
        $id = $this->createActivity(['subject_code' => 'WEB001', 'title' => 'ใบงานวิชาเว็บ']);
        Sanctum::actingAs($this->student());
        $response = $this->getJson(self::BASE.'?subject_code=WEB001&education_level=3')->assertOk()->assertJsonPath('data.selected_activity.id', (string) $id);
        $codes = array_column($response->json('data.subjects'), 'code');
        $this->assertContains('WEB001', $codes);
        $this->assertNotContains('NO001', $codes);
        $this->assertNotContains('TR001', $codes);
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertOk();
    }

    public function test_draft_expired_and_unregistered_student_access_is_denied(): void
    {
        $draft = $this->createActivity(['status' => 'draft']);
        $expired = $this->createActivity(['due_at' => now()->subMinute()->toISOString()]);
        Sanctum::actingAs($this->student());
        $this->getJson($this->selected($draft))->assertNotFound();
        $this->postJson(self::BASE."/activities/{$draft}/submit", ['answers' => ['q1' => 'ก']])->assertNotFound();
        $this->postJson(self::BASE."/activities/{$expired}/submit", ['answers' => ['q1' => 'ก']])->assertUnprocessable();
        Sanctum::actingAs($this->student('6650300007'));
        $this->getJson($this->selected($expired))->assertNotFound();
        $this->postJson(self::BASE."/activities/{$expired}/submit", ['answers' => ['q1' => 'ก']])->assertNotFound();
        Sanctum::actingAs($this->student('unknown'));
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data.subjects');
    }

    public function test_teacher_requires_explicit_assigned_group_and_owner_is_required(): void
    {
        $this->postJson(self::BASE.'/activities', $this->values(['target_group' => '']))->assertUnprocessable()->assertJsonValidationErrors('target_group');
        $id = $this->createActivity();
        DB::table('learning_classroom_activities')->where('id', $id)->update(['target_group' => '']);
        Sanctum::actingAs($this->student('6650300007'));
        $this->getJson($this->selected($id))->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]));
        $this->postJson(self::BASE."/activities/{$id}", $this->values())->assertForbidden();
        $this->deleteJson(self::BASE."/activities/{$id}")->assertForbidden();
        $this->getJson($this->selected($id))->assertNotFound();
        $this->postJson(self::BASE.'/activities', $this->values(['target_group' => 'SENA-M3-B']))->assertUnprocessable();
        Sanctum::actingAs($this->student());
        $this->postJson(self::BASE.'/activities', $this->values())->assertForbidden();
    }

    public function test_admin_superadmin_and_district_isolation_are_enforced(): void
    {
        $id = $this->createActivity();
        $other = District::create(['name' => 'บางซ้าย', 'code' => 'bangsai', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $other->id]));
        $this->postJson(self::BASE."/activities/{$id}", ['status' => 'draft'])->assertNotFound();
        $this->withHeader('X-District-Id', (string) $this->district->id)->getJson(self::BASE)->assertForbidden();
        $this->flushHeaders();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->getJson(self::BASE)->assertUnprocessable();
        $this->withHeader('X-District-Id', (string) $this->district->id)->getJson($this->selected($id))->assertOk();
        $this->postJson(self::BASE."/activities/{$id}", ['status' => 'draft'])->assertOk();
    }

    public function test_invalid_keys_options_urls_and_answers_are_rejected_without_records(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,evil', 'https://user:pass@example.test/path'] as $url) {
            $this->postJson(self::BASE.'/activities', $this->values(['links' => [['title' => 'สื่อ', 'url' => $url]]]))->assertUnprocessable();
        }
        $this->postJson(self::BASE.'/activities', ['status' => 'published'])->assertUnprocessable();
        $this->postJson(self::BASE.'/activities', $this->values(['questions' => [$this->question('q1', 'single_choice', 'ไม่มีในตัวเลือก')]]))->assertUnprocessable();
        $this->postJson(self::BASE.'/activities', $this->values(['questions' => [$this->question(), $this->question()]]))->assertUnprocessable();
        $this->assertDatabaseCount('learning_classroom_activities', 0);
        $id = $this->createActivity();
        Sanctum::actingAs($this->student());
        foreach ([['unknown' => 'ก'], ['q1' => ['ก']], ['q1' => 'ง'], ['q1' => ['nested' => ['array']]]] as $answers) {
            $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => $answers])->assertUnprocessable();
        }
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
    }

    public function test_wrong_and_partial_multiple_answers_receive_zero_and_zero_review_is_preserved(): void
    {
        $id = $this->createActivity(['questions' => [$this->question('q1', 'multiple_choice', ['ก', 'ค'])]]);
        Sanctum::actingAs($this->student());
        $submission = $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => ['ก']]])->assertOk()->assertJsonPath('data.score', 0)->json('data.id');
        Sanctum::actingAs($this->teacher);
        $this->patchJson(self::BASE."/activities/{$id}/submissions/{$submission}/review", ['score' => 0, 'feedback' => 'ลองทบทวนใหม่'])->assertOk()->assertJsonPath('data.score', 0);
    }

    public function test_images_are_validated_private_and_authorized_by_published_registration(): void
    {
        Storage::fake('local');
        $this->post(self::BASE.'/images', ['image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $uploaded = $this->post(self::BASE.'/images', ['image' => UploadedFile::fake()->image('โจทย์.png', 30, 30)], ['Accept' => 'application/json'])->assertCreated();
        $image = $uploaded->json('data.id');
        $url = $uploaded->json('data.url');
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Sanctum::actingAs($this->student());
        $this->get($url)->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $id = $this->createActivity(['questions' => [array_replace($this->question(), ['image_id' => $image])]]);
        Sanctum::actingAs($this->student());
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        Sanctum::actingAs($this->student('6650300007'));
        $this->get($url)->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $this->postJson(self::BASE."/activities/{$id}", ['status' => 'draft'])->assertOk();
        Sanctum::actingAs($this->student());
        $this->get($url)->assertNotFound();
    }

    public function test_another_teacher_cannot_attach_unassigned_image(): void
    {
        Storage::fake('local');
        $image = $this->post(self::BASE.'/images', ['image' => UploadedFile::fake()->image('question.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['SENA-M3-A']]));
        $this->createActivity();
        $this->postJson(self::BASE.'/activities', $this->values(['questions' => [array_replace($this->question(), ['image_id' => $image])]]))->assertUnprocessable();
    }

    public function test_missing_migration_returns_actionable_error_and_write_disabled_fails_closed(): void
    {
        config(['system_data.write_enabled' => false]);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.read_only', true);
        $this->postJson(self::BASE.'/activities', $this->values())->assertStatus(503);
        Schema::drop('learning_classroom_submissions');
        $this->getJson(self::BASE)->assertStatus(503)->assertJsonPath('message', 'ห้องเรียนออนไลน์ยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
    }

    public function test_online_classroom_is_reachable_in_catalog_and_student_homepage(): void
    {
        Sanctum::actingAs($this->student());
        $response = $this->getJson('/api/v1/system/catalog')->assertOk();
        $this->assertTrue(collect($response->json('data.groups'))->flatMap(fn ($group) => $group['items'])->contains('key', 'online-classroom'));
        $menu = app(StudentDashboardMenu::class)->items($this->district->id);
        $this->assertTrue(collect($menu)->firstWhere('key', 'online-classroom')['visible']);
    }

    public function test_group_filter_runs_before_pagination_and_does_not_hide_eligible_activity(): void
    {
        $id = $this->createActivity();
        $row = (array) DB::table('learning_classroom_activities')->where('id', $id)->first();
        unset($row['id']);
        $row['target_group'] = 'SENA-M3-B';
        $row['audience_groups'] = json_encode(['SENA-M3-B']);
        DB::table('learning_classroom_activities')->insert(array_fill(0, 55, $row));
        Sanctum::actingAs($this->student());
        $this->getJson($this->selected($id))->assertOk()->assertJsonCount(1, 'data.activities')->assertJsonPath('data.activity_pagination.total', 1);
    }

    public function test_two_students_only_receive_their_own_answers_and_scores(): void
    {
        $this->teacher->update(['assigned_groups' => ['SENA-M3-A', 'SENA-M3-B']]);
        $id = $this->createActivity();
        DB::table('learning_classroom_activities')->where('id', $id)->update(['target_group' => '']);
        $student = $this->student();
        Sanctum::actingAs($student);
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertOk()->assertJsonPath('data.score', 2);
        Sanctum::actingAs($this->student('6650300007'));
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ข']])->assertOk()->assertJsonPath('data.score', 0);
        Sanctum::actingAs($student);
        $this->getJson($this->selected($id))->assertOk()->assertJsonCount(0, 'data.submissions')
            ->assertJsonPath('data.selected_activity.submission.student_code', '6650300005')
            ->assertJsonPath('data.selected_activity.submission.answers.q1', 'ก')->assertJsonPath('data.selected_activity.submission.score', 2);
        Sanctum::actingAs($this->teacher);
        $this->getJson($this->selected($id))->assertOk()->assertJsonCount(2, 'data.submissions');
        $this->teacher->update(['assigned_groups' => ['SENA-M3-A']]);
        $this->getJson($this->selected($id))->assertOk()->assertJsonCount(1, 'data.submissions')->assertJsonPath('data.selected_activity.submission_count', 1)->assertJsonPath('data.selected_activity.can_edit', false);
    }

    public function test_deadlines_are_normalized_for_mysql_and_migration_is_repeatable_without_data_loss(): void
    {
        $id = $this->createActivity(['due_at' => '2030-01-01T00:00:00.000Z']);
        $this->assertDatabaseHas('learning_classroom_activities', ['id' => $id, 'due_at' => '2030-01-01 07:00:00']);
        Sanctum::actingAs($this->student());
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertOk();
        $migration = require database_path('migrations/2026_10_28_000037_create_online_classroom_tables.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('learning_classroom_submissions', 1);
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.due_at', '2030-01-01T07:00:00+07:00');
    }

    public function test_teacher_cannot_change_all_group_activity_after_losing_one_assigned_group(): void
    {
        $this->teacher->update(['assigned_groups' => ['SENA-M3-A', 'SENA-M3-B']]);
        $id = $this->createActivity();
        DB::table('learning_classroom_activities')->where('id', $id)->update(['target_group' => '']);
        $this->teacher->update(['assigned_groups' => ['SENA-M3-A']]);
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.can_edit', false);
        $this->postJson(self::BASE."/activities/{$id}", ['status' => 'draft'])->assertForbidden();
        $this->deleteJson(self::BASE."/activities/{$id}")->assertForbidden();
    }

    public function test_video_is_a_separate_published_activity_with_links_and_no_submission(): void
    {
        $links = [['title' => 'คลิปการทดลอง', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['title' => 'คลิปจาก Drive', 'url' => 'https://drive.google.com/file/d/example/view'],
            ['title' => 'คลิปจาก OneDrive', 'url' => 'https://1drv.ms/v/s!example']];
        $id = $this->createActivity(['type' => 'video', 'questions' => [], 'links' => $links]);
        Sanctum::actingAs($this->student());
        $this->getJson($this->selected($id))->assertOk()->assertJsonPath('data.selected_activity.type', 'video')
            ->assertJsonPath('data.selected_activity.links', $links)->assertJsonCount(0, 'data.selected_activity.questions')
            ->assertJsonPath('data.activity_counts.video', 1)->assertJsonPath('data.activity_counts.knowledge', 0);
        $this->postJson(self::BASE."/activities/{$id}/submit", ['answers' => ['q1' => 'ก']])->assertUnprocessable();
        $this->assertDatabaseCount('learning_classroom_submissions', 0);
    }

    public function test_video_requires_a_safe_link_and_cannot_contain_quiz_questions(): void
    {
        $this->postJson(self::BASE.'/activities', $this->values(['type' => 'video', 'links' => [], 'questions' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('links');
        $this->postJson(self::BASE.'/activities', $this->values(['type' => 'video', 'links' => [['title' => 'คลิป', 'url' => 'javascript:alert(1)']], 'questions' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('links.0.url');
        $this->postJson(self::BASE.'/activities', $this->values(['type' => 'video', 'links' => [['title' => 'คลิป', 'url' => 'https://youtu.be/dQw4w9WgXcQ']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('questions');
        $this->assertDatabaseCount('learning_classroom_activities', 0);
    }

    public function test_activity_type_filter_runs_before_pagination_and_counts_only_authorized_published_activities(): void
    {
        $knowledge = $this->createActivity(['type' => 'knowledge', 'questions' => []]);
        $video = $this->createActivity(['type' => 'video', 'questions' => [], 'links' => [['title' => 'คลิป', 'url' => 'https://youtu.be/dQw4w9WgXcQ']]]);
        $quiz = $this->createActivity();
        $quizRow = (array) DB::table('learning_classroom_activities')->where('id', $quiz)->first();
        unset($quizRow['id']);
        DB::table('learning_classroom_activities')->insert(array_fill(0, 55, $quizRow));
        $videoRow = (array) DB::table('learning_classroom_activities')->where('id', $video)->first();
        unset($videoRow['id']);
        DB::table('learning_classroom_activities')->insert([
            array_replace($videoRow, ['status' => 'draft']),
            array_replace($videoRow, ['target_group' => 'SENA-M3-B', 'audience_groups' => json_encode(['SENA-M3-B'])]),
        ]);
        Sanctum::actingAs($this->student());
        $response = $this->getJson(self::BASE.'?subject_code='.urlencode('พว31001').'&education_level=3&activity_type=knowledge')
            ->assertOk()->assertJsonCount(1, 'data.activities')->assertJsonPath('data.activity_pagination.total', 1)
            ->assertJsonPath('data.selected_activity.id', (string) $knowledge)
            ->assertJsonPath('data.activity_counts', ['all' => 58, 'knowledge' => 1, 'video' => 1, 'worksheet' => 0, 'quiz' => 56]);
        $this->assertSame('knowledge', $response->json('data.selected_activity.type'));
        $this->getJson($this->selected($knowledge).'&activity_type=video')->assertNotFound();
        $this->getJson(self::BASE.'?subject_code='.urlencode('พว31001').'&education_level=3&activity_type=worksheet')
            ->assertOk()->assertJsonCount(0, 'data.activities')->assertJsonPath('data.selected_activity', null)
            ->assertJsonPath('data.activity_counts.all', 58);
        $this->getJson(self::BASE.'?activity_type=unknown')->assertUnprocessable();
    }

    public function test_empty_classroom_has_zero_category_counts(): void
    {
        Sanctum::actingAs($this->student('unknown'));
        $this->getJson(self::BASE.'?activity_type=video')->assertOk()->assertJsonPath('data.selected_activity', null)
            ->assertJsonPath('data.activity_counts', ['all' => 0, 'knowledge' => 0, 'video' => 0, 'worksheet' => 0, 'quiz' => 0]);
    }
}
