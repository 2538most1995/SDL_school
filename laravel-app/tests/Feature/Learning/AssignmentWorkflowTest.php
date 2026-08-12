<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'system_data.enabled' => true,
            'system_data.student_enabled' => false,
            'system_data.write_enabled' => true,
        ]);
        $this->district = District::create([
            'name' => 'อำเภอเสนา',
            'code' => 'sena',
            'is_active' => true,
        ]);
    }

    public function test_teacher_creates_current_term_assignment_and_reviews_student_link(): void
    {
        $teacher = $this->teacher(['SENA-M3-A']);
        Sanctum::actingAs($teacher);

        $catalog = $this->getJson('/api/v1/learning/assignments')
            ->assertOk()
            ->assertJsonPath('data.term', '2/2568')
            ->assertJsonPath('meta.roster_source', 'current_term_registrations');
        $this->assertContains('พว31001', array_column($catalog->json('data.subjects'), 'code'));

        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'แผนผังความคิด เรื่อง วิธีการเรียนรู้',
            'instructions' => 'สรุปเนื้อหาให้อ่านง่ายและอ้างอิงแหล่งข้อมูล',
            'subject_code' => 'พว31001',
            'education_level' => 3,
            'target_group' => 'SENA-M3-A',
            'max_score' => 20,
            'opens_at' => now()->subHour()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
            'status' => 'open',
        ])->assertCreated();
        $assignmentId = (int) $created->json('data.id');

        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.selected_assignment.subject_name', 'วิทยาศาสตร์')
            ->assertJsonPath('data.selected_assignment.student_count', 1)
            ->assertJsonPath('data.students.0.student_code', '6650300005')
            ->assertJsonPath('data.students.0.submission', null);

        $student = $this->student('6650300005');
        Sanctum::actingAs($student);
        $this->getJson('/api/v1/learning/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.title', 'แผนผังความคิด เรื่อง วิธีการเรียนรู้');
        $submitted = $this->postJson("/api/v1/learning/assignments/{$assignmentId}/submit", [
            'submission_type' => 'link',
            'url' => 'https://drive.google.com/file/d/student-work',
        ])->assertOk()
            ->assertJsonPath('data.type', 'link')
            ->assertJsonPath('data.status', 'submitted');
        $submissionId = (int) $submitted->json('data.id');

        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.selected_assignment.submitted_count', 1)
            ->assertJsonPath('data.students.0.submission.url', 'https://drive.google.com/file/d/student-work');
        $this->patchJson("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}", [
            'score' => 18,
            'feedback' => 'จัดหมวดหมู่ชัดเจนและอ่านง่าย',
        ])->assertOk()
            ->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.score', 18);

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.selected_assignment.submission.score', 18)
            ->assertJsonPath('data.selected_assignment.submission.feedback', 'จัดหมวดหมู่ชัดเจนและอ่านง่าย');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'learning.assignment.reviewed',
            'district_id' => $this->district->id,
            'auditable_id' => $assignmentId,
        ]);
    }

    public function test_pdf_submission_is_private_and_assignment_scope_fails_closed(): void
    {
        Storage::fake('local');
        $teacher = $this->teacher(['SENA-M3-A']);
        Sanctum::actingAs($teacher);
        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'รายงานการทดลอง',
            'subject_code' => 'พว31001',
            'education_level' => 3,
            'target_group' => 'SENA-M3-A',
            'max_score' => 20,
            'opens_at' => now()->subHour()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
            'status' => 'open',
        ])->assertCreated();
        $assignmentId = (int) $created->json('data.id');

        $student = $this->student('6650300005');
        Sanctum::actingAs($student);
        $submitted = $this->post("/api/v1/learning/assignments/{$assignmentId}/submit", [
            'submission_type' => 'pdf',
            'file' => UploadedFile::fake()->create('mindmap.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $submissionId = (int) $submitted->json('data.id');
        $path = (string) $this->app['db']->table('learning_submissions')->where('id', $submissionId)->value('attachment_path');
        Storage::disk('local')->assertExists($path);
        $this->get("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}/file")->assertOk();

        Sanctum::actingAs($this->student('6650300006'));
        $this->getJson('/api/v1/learning/assignments')->assertJsonCount(0, 'data.assignments');
        $this->get("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}/file")->assertNotFound();
        $this->postJson("/api/v1/learning/assignments/{$assignmentId}/submit", [
            'submission_type' => 'link',
            'url' => 'https://example.test/out-of-scope',
        ])->assertNotFound();

        Sanctum::actingAs($this->teacher(['SENA-M2-A']));
        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")->assertNotFound();
        $this->patchJson("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}", [
            'score' => 10,
        ])->assertNotFound();
    }

    /** @param list<string> $groups */
    private function teacher(array $groups): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'district_id' => $this->district->id,
            'assigned_groups' => $groups,
        ]);
    }

    private function student(string $studentCode): User
    {
        return User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => $studentCode,
            'username' => 'student-'.$studentCode,
        ]);
    }
}
