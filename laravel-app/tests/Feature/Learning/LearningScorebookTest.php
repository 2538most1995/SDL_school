<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LearningScorebookTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'system_data.enabled' => false,
            'system_data.student_enabled' => false,
            'system_data.write_enabled' => true,
        ]);
        $this->district = District::create([
            'name' => 'อำเภอเสนา',
            'code' => 'sena',
            'is_active' => true,
        ]);
    }

    public function test_teacher_creates_scorebook_from_registered_subjects_and_saves_scores(): void
    {
        $teacher = $this->teacher(['SENA-M3-A', 'SENA-M2-A']);
        Sanctum::actingAs($teacher);

        $subject = rawurlencode('พว31001');
        $this->getJson("/api/v1/learning/scores/workspace?term=2/2568&subject_code={$subject}&level=3&group=SENA-M3-A")
            ->assertOk()
            ->assertJsonPath('data.selected_term', '2/2568')
            ->assertJsonCount(6, 'data.subjects')
            ->assertJsonPath('data.selected_subject.code', 'พว31001')
            ->assertJsonPath('data.selected_subject.student_count', 1)
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student_code', '6650300005')
            ->assertJsonPath('data.scorebook', null);

        $created = $this->postJson('/api/v1/learning/scores/scorebooks', [
            'term' => '2/2568',
            'subject_code' => 'พว31001',
            'level' => 3,
            'group' => 'SENA-M3-A',
            'score_ratio' => '70:30',
            'components' => [
                ['category' => 'coursework', 'title' => 'คะแนนเก็บครั้งที่ 1', 'max_score' => 20],
                ['category' => 'coursework', 'title' => 'ใบงานที่ 1', 'max_score' => 10],
                ['category' => 'coursework', 'title' => 'แบบทดสอบย่อย', 'max_score' => 20],
                ['category' => 'coursework', 'title' => 'การนำเสนอ', 'max_score' => 20],
                ['category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 30],
            ],
        ])->assertCreated()->assertJsonCount(5, 'data.components');
        $scorebookId = (int) $created->json('data.id');
        $components = $created->json('data.components');

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/entries", [
            'students' => [[
                'student_code' => '6650300005',
                'note' => 'ตั้งใจเรียน',
                'scores' => array_map(
                    static fn (array $component, float $score): array => ['component_id' => $component['id'], 'score' => $score],
                    $components,
                    [18, 9, 16, 17, 25],
                ),
            ]],
        ])->assertOk()->assertJsonPath('data.saved_students', 1);

        $this->getJson("/api/v1/learning/scores/workspace?term=2/2568&subject_code={$subject}&level=3&group=SENA-M3-A&scorebook_id={$scorebookId}")
            ->assertOk()
            ->assertJsonPath('data.scorebook.maximum_score', 100)
            ->assertJsonPath('data.scorebook.score_ratio', '70:30')
            ->assertJsonPath('data.students.0.coursework_score', 60)
            ->assertJsonPath('data.students.0.final_exam_score', 25)
            ->assertJsonPath('data.students.0.total', 85)
            ->assertJsonPath('data.students.0.note', 'ตั้งใจเรียน')
            ->assertJsonPath('data.scorebook.can_edit', true);

        $this->assertDatabaseHas('audit_logs', [
            'district_id' => $this->district->id,
            'event' => 'learning.scorebook.entries_saved',
            'auditable_id' => $scorebookId,
        ]);

        DB::table('learning_score_entries')->insert([
            'scorebook_id' => $scorebookId,
            'component_id' => (int) $components[0]['id'],
            'student_code' => 'OTHER-STUDENT',
            'score' => 20,
            'updated_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => '6650300005',
            'username' => 'student-6650300005',
        ]));
        $this->getJson('/api/v1/learning/scores')
            ->assertOk()
            ->assertJsonPath('data.term', '2/2568')
            ->assertJsonPath('data.courses.0.subject_code', 'พว31001')
            ->assertJsonPath('data.courses.0.score_ratio', '70:30')
            ->assertJsonPath('data.courses.0.assignment_score', 60)
            ->assertJsonPath('data.courses.0.exam_score', 25)
            ->assertJsonPath('data.courses.0.total_score', 85)
            ->assertJsonPath('data.courses.0.note', 'ตั้งใจเรียน')
            ->assertJsonMissing(['student_code' => 'OTHER-STUDENT']);

        DB::table('learning_score_entries')
            ->where('scorebook_id', $scorebookId)
            ->where('student_code', '6650300005')
            ->where('component_id', (int) $components[4]['id'])
            ->delete();
        $this->getJson('/api/v1/learning/scores')
            ->assertOk()
            ->assertJsonPath('data.courses.0.maximum_score', 100)
            ->assertJsonPath('data.courses.0.total_score', 60)
            ->assertJsonPath('data.courses.0.components.4.score', null);
    }

    public function test_scorebook_writes_fail_closed_for_scope_and_score_limits(): void
    {
        $teacher = $this->teacher(['SENA-M3-A']);
        Sanctum::actingAs($teacher);
        $created = $this->postJson('/api/v1/learning/scores/scorebooks', [
            'term' => '2/2568',
            'subject_code' => 'พว31001',
            'level' => 3,
            'group' => 'SENA-M3-A',
            'score_ratio' => '80:20',
            'components' => [
                ['category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => 80],
                ['category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 20],
            ],
        ])->assertCreated();
        $scorebookId = (int) $created->json('data.id');
        $componentId = (int) $created->json('data.components.0.id');

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/entries", [
            'students' => [[
                'student_code' => '6650300007',
                'scores' => [['component_id' => $componentId, 'score' => 18]],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.0.student_code');

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/entries", [
            'students' => [[
                'student_code' => '6650300005',
                'scores' => [['component_id' => $componentId, 'score' => 81]],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.0.scores.0.score');

        $this->postJson('/api/v1/learning/scores/scorebooks', [
            'term' => '2/2568',
            'subject_code' => 'พว31001',
            'level' => 3,
            'group' => 'SENA-M3-A',
            'score_ratio' => '60:40',
            'components' => [
                ['category' => 'coursework', 'title' => 'ช่อง 1', 'max_score' => 60],
                ['category' => 'final_exam', 'title' => 'ช่อง 2', 'max_score' => 50],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('components');

        $coTeacher = $this->teacher(['SENA-M3-A']);
        Sanctum::actingAs($coTeacher);
        $this->getJson("/api/v1/learning/scores/workspace?term=2/2568&subject_code=%E0%B8%9E%E0%B8%A731001&level=3&group=SENA-M3-A&scorebook_id={$scorebookId}")
            ->assertOk()
            ->assertJsonPath('data.scorebook.can_edit', true);
        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/structure", [
            'score_ratio' => '80:20',
            'components' => [
                ['id' => $componentId, 'category' => 'coursework', 'title' => 'คะแนนเก็บร่วม', 'max_score' => 80],
                ['category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 20],
            ],
        ])->assertOk();

        $components = DB::table('learning_score_components')
            ->where('scorebook_id', $scorebookId)
            ->orderBy('position')
            ->get();
        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/entries", [
            'students' => [[
                'student_code' => '6650300005',
                'scores' => [
                    ['component_id' => $components[0]->id, 'score' => 75],
                    ['component_id' => $components[1]->id, 'score' => 18],
                ],
            ]],
        ])->assertOk()->assertJsonPath('data.saved_students', 1);

        Sanctum::actingAs($this->teacher(['SENA-M2-A']));
        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/entries", [
            'students' => [[
                'student_code' => '6650300005',
                'scores' => [['component_id' => $components[0]->id, 'score' => 70]],
            ]],
        ])->assertNotFound();
    }

    public function test_score_structure_accepts_supported_ratios_and_rejects_invalid_structures(): void
    {
        Sanctum::actingAs($this->teacher(['SENA-M3-A']));
        $created = $this->postJson('/api/v1/learning/scores/scorebooks', [
            'term' => '2/2568',
            'subject_code' => 'พว31001',
            'level' => 3,
            'group' => 'SENA-M3-A',
            'score_ratio' => '60:40',
            'components' => [
                ['category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => 60],
                ['category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 40],
            ],
        ])->assertCreated()->assertJsonPath('data.score_ratio', '60:40');
        $scorebookId = (int) $created->json('data.id');
        $courseworkId = (int) $created->json('data.components.0.id');
        $examId = (int) $created->json('data.components.1.id');

        foreach ([[70, 30], [80, 20]] as [$coursework, $exam]) {
            $ratio = "{$coursework}:{$exam}";
            $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/structure", [
                'score_ratio' => $ratio,
                'components' => [
                    ['id' => $courseworkId, 'category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => $coursework],
                    ['id' => $examId, 'category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => $exam],
                ],
            ])->assertOk()->assertJsonPath('data.score_ratio', $ratio);
        }

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/structure", [
            'score_ratio' => '50:50',
            'components' => [
                ['id' => $courseworkId, 'category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => 50],
                ['id' => $examId, 'category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 50],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('score_ratio');

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/structure", [
            'score_ratio' => '70:30',
            'components' => [
                ['id' => $courseworkId, 'category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => 60],
                ['id' => $examId, 'category' => 'final_exam', 'title' => 'สอบปลายภาค', 'max_score' => 40],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('components');

        $this->putJson("/api/v1/learning/scores/scorebooks/{$scorebookId}/structure", [
            'score_ratio' => '70:30',
            'components' => [
                ['id' => $courseworkId, 'category' => 'coursework', 'title' => 'คะแนนเก็บ', 'max_score' => 70],
                ['id' => $examId, 'category' => 'coursework', 'title' => 'ไม่มีปลายภาค', 'max_score' => 30],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('components');
    }

    public function test_student_cannot_open_scorebook_workspace_or_write_scores(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => '6650300005',
            'username' => 'student-session',
        ]));

        $this->getJson('/api/v1/learning/scores/workspace')->assertForbidden();
        $this->postJson('/api/v1/learning/scores/scorebooks', [])->assertForbidden();
    }

    public function test_legacy_scorebook_without_ratio_remains_readable(): void
    {
        $teacher = $this->teacher(['SENA-M3-A']);
        $scorebookId = DB::table('learning_scorebooks')->insertGetId([
            'district_id' => $this->district->id,
            'created_by' => $teacher->id,
            'academic_term' => '2/2568',
            'subject_code' => 'พว31001',
            'subject_name' => 'วิทยาศาสตร์',
            'education_level' => 3,
            'group_code' => 'SENA-M3-A',
            'coursework_weight' => null,
            'final_exam_weight' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $componentId = DB::table('learning_score_components')->insertGetId([
            'scorebook_id' => $scorebookId,
            'category' => 'coursework',
            'title' => 'คะแนนเดิม',
            'max_score' => 20,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learning_score_entries')->insert([
            'scorebook_id' => $scorebookId,
            'component_id' => $componentId,
            'student_code' => '6650300005',
            'score' => 15,
            'updated_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'student',
            'district_id' => $this->district->id,
            'student_code' => '6650300005',
            'username' => 'student-legacy-score',
        ]));

        $this->getJson('/api/v1/learning/scores')
            ->assertOk()
            ->assertJsonPath('data.courses.0.score_ratio', null)
            ->assertJsonPath('data.courses.0.assignment_score', 15)
            ->assertJsonPath('data.courses.0.exam_score', null)
            ->assertJsonPath('data.courses.0.total_score', 15);
    }

    public function test_git_only_learning_readiness_repairs_scorebook_tables_and_indexes(): void
    {
        Schema::drop('learning_score_notes');
        Schema::drop('learning_score_entries');
        Schema::drop('learning_score_components');
        Schema::drop('learning_scorebooks');
        config(['system_data.enabled' => true, 'system_data.student_enabled' => false, 'system_data.write_enabled' => false]);
        Sanctum::actingAs($this->teacher(['SENA-M3-A']));

        $this->getJson('/api/v1/learning/scores/workspace')
            ->assertOk()
            ->assertJsonPath('meta.read_only', true);

        foreach (['learning_scorebooks', 'learning_score_components', 'learning_score_entries', 'learning_score_notes'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasIndex('learning_scorebooks', 'learning_scorebooks_course_scope_unique'));
        $this->assertTrue(Schema::hasIndex('learning_score_components', 'learning_score_components_scorebook_id_position_unique'));
        $this->assertTrue(Schema::hasIndex('learning_score_entries', 'learning_score_entries_unique'));
        $this->assertTrue(Schema::hasIndex('learning_score_notes', 'learning_score_notes_scorebook_id_student_code_unique'));
        $this->assertTrue(Schema::hasColumns('learning_scorebooks', ['coursework_weight', 'final_exam_weight']));
        $this->assertTrue(Schema::hasColumn('learning_score_components', 'category'));

        Schema::table('learning_score_entries', function ($table): void {
            $table->dropUnique('learning_score_entries_unique');
        });
        $this->assertFalse(Schema::hasIndex('learning_score_entries', 'learning_score_entries_unique'));

        $this->getJson('/api/v1/learning/scores/workspace')->assertOk();
        $this->assertTrue(Schema::hasIndex('learning_score_entries', 'learning_score_entries_unique'));
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
}
