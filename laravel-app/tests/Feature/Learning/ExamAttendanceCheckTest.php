<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ExamAttendanceCheckTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => false, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena-attendance', 'is_active' => true]);
    }

    public function test_teacher_checks_attendance_by_subject_and_reads_statistics_by_student(): void
    {
        Sanctum::actingAs($this->teacher(['SENA-M3-B']));

        $workspace = $this->getJson('/api/v1/learning/exam-attendance/workspace?view=subject&term=2/2568&level=3&group=SENA-M3-B&subject_code='.rawurlencode('พว31001'))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.summary.registered_students', 2)
            ->assertJsonPath('data.summary.attended_students', 0)
            ->assertJsonPath('data.summary.absent_students', 2)
            ->assertJsonPath('data.summary.attendance_rate', 0);

        $items = $workspace->json('data.items');
        $this->putJson('/api/v1/learning/exam-attendance', [
            'term' => '2/2568',
            'records' => array_map(static fn (array $item, int $index): array => [
                'student_code' => $item['student_code'],
                'subject_code' => $item['subject_code'],
                'level' => $item['level'],
                'attended' => $index === 0,
            ], $items, array_keys($items)),
        ])->assertOk()->assertJsonPath('data.saved_records', 2);

        $this->getJson('/api/v1/learning/exam-attendance/workspace?view=subject&term=2/2568&level=3&group=SENA-M3-B&subject_code='.rawurlencode('พว31001'))
            ->assertOk()
            ->assertJsonPath('data.summary.attended_students', 1)
            ->assertJsonPath('data.summary.absent_students', 1)
            ->assertJsonPath('data.summary.attendance_rate', 50)
            ->assertJsonPath('data.items.0.recorded', true)
            ->assertJsonPath('data.items.1.recorded', true);

        $studentCode = $items[0]['student_code'];
        $this->getJson("/api/v1/learning/exam-attendance/workspace?view=student&term=2/2568&level=3&student_code={$studentCode}")
            ->assertOk()
            ->assertJsonPath('data.selected_student.student_code', $studentCode)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.summary.attended_students', 1)
            ->assertJsonPath('data.summary.absent_students', 2)
            ->assertJsonPath('data.summary.attendance_rate', 33.3);

        $this->assertDatabaseHas('audit_logs', ['event' => 'learning.exam_attendance.saved']);
    }

    public function test_attendance_excludes_disqualified_students_and_rejects_out_of_scope_writes(): void
    {
        Sanctum::actingAs($this->teacher(['SENA-M2-A']));
        $this->getJson('/api/v1/learning/exam-attendance/workspace?view=subject&term=2/2568&level=2&group=SENA-M2-A&subject_code='.rawurlencode('พว21001'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.student_code', '6650200004');

        $this->putJson('/api/v1/learning/exam-attendance', [
            'term' => '2/2568',
            'records' => [['student_code' => '6650300005', 'subject_code' => 'พว31001', 'level' => 3, 'attended' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('records.0');

        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $this->district->id, 'student_code' => '6650200004']));
        $this->getJson('/api/v1/learning/exam-attendance/workspace')->assertForbidden();
        $this->putJson('/api/v1/learning/exam-attendance', ['term' => '2/2568', 'records' => []])->assertForbidden();
    }

    /** @param list<string> $groups */
    private function teacher(array $groups): User
    {
        return User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => $groups]);
    }
}
