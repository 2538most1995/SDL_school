<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $created = $this->post('/api/v1/learning/assignments', [
            'title' => 'รายงานการทดลอง',
            'instructions' => 'อ่านใบงานจากครูแล้วสรุปผลการทดลองเป็น PDF',
            'subject_code' => 'พว31001',
            'education_level' => 3,
            'target_group' => 'SENA-M3-A',
            'max_score' => 20,
            'opens_at' => now()->subHour()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
            'status' => 'open',
            'material_url' => 'https://example.test/experiment-video',
            'material_pdf' => UploadedFile::fake()->create('ใบงานการทดลอง.pdf', 180, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $assignmentId = (int) $created->json('data.id');
        $materialPath = (string) $this->app['db']->table('learning_assignments')
            ->where('id', $assignmentId)->value('material_path');
        Storage::disk('local')->assertExists($materialPath);
        $this->get("/api/v1/learning/assignments/{$assignmentId}/material")->assertOk();
        $this->post("/api/v1/learning/assignments/{$assignmentId}", [
            '_method' => 'PATCH',
            'title' => 'รายงานการทดลอง',
            'instructions' => 'อ่านใบงานจากครูแล้วสรุปผลการทดลองเป็น PDF',
            'subject_code' => 'พว31001',
            'education_level' => 3,
            'target_group' => 'SENA-M3-A',
            'max_score' => 20,
            'opens_at' => now()->subHour()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
            'status' => 'open',
            'material_url' => 'https://example.test/experiment-video',
            'remove_material_pdf' => '0',
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(
            $materialPath,
            (string) $this->app['db']->table('learning_assignments')->where('id', $assignmentId)->value('material_path'),
        );

        $student = $this->student('6650300005');
        Sanctum::actingAs($student);
        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")
            ->assertOk()
            ->assertJsonPath('data.selected_assignment.instructions', 'อ่านใบงานจากครูแล้วสรุปผลการทดลองเป็น PDF')
            ->assertJsonPath('data.selected_assignment.material_url', 'https://example.test/experiment-video')
            ->assertJsonPath('data.selected_assignment.material_filename', 'ใบงานการทดลอง.pdf')
            ->assertJsonPath('data.selected_assignment.material_download_url', "/api/v1/learning/assignments/{$assignmentId}/material");
        $this->get("/api/v1/learning/assignments/{$assignmentId}/material")->assertOk();
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
        $this->get("/api/v1/learning/assignments/{$assignmentId}/material")->assertNotFound();
        $this->get("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}/file")->assertNotFound();
        $this->postJson("/api/v1/learning/assignments/{$assignmentId}/submit", [
            'submission_type' => 'link',
            'url' => 'https://example.test/out-of-scope',
        ])->assertNotFound();

        Sanctum::actingAs($this->teacher(['SENA-M2-A']));
        $this->getJson("/api/v1/learning/assignments?assignment_id={$assignmentId}")->assertNotFound();
        $this->get("/api/v1/learning/assignments/{$assignmentId}/material")->assertNotFound();
        $this->patchJson("/api/v1/learning/assignments/{$assignmentId}/submissions/{$submissionId}", [
            'score' => 10,
        ])->assertNotFound();
    }

    public function test_assignment_save_succeeds_when_audit_storage_is_temporarily_unavailable(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is specific to the SQLite test connection.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_assignment_audit
            BEFORE INSERT ON audit_logs
            WHEN NEW.event = 'learning.assignment.created'
            BEGIN
                SELECT RAISE(FAIL, 'audit unavailable');
            END
        SQL);

        Sanctum::actingAs($this->teacher(['SENA-M3-A']));
        $created = $this->postJson('/api/v1/learning/assignments', [
            'title' => 'งานที่ต้องบันทึกแม้ระบบตรวจสอบเหตุการณ์ขัดข้อง',
            'subject_code' => 'พว31001',
            'education_level' => 3,
            'target_group' => 'SENA-M3-A',
            'max_score' => 10,
            'due_at' => now()->addDay()->toDateTimeString(),
            'status' => 'open',
        ])->assertCreated();

        $this->assertDatabaseHas('learning_assignments', [
            'id' => (int) $created->json('data.id'),
            'title' => 'งานที่ต้องบันทึกแม้ระบบตรวจสอบเหตุการณ์ขัดข้อง',
        ]);
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
