<?php

namespace Tests\Feature\Learning;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\NnetResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NnetTeacherScopeTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private User $teacher;

    private array $currentStudents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'district_id' => $this->district->id, 'assigned_groups' => ['G1']]);
        $this->roster([$this->student('SAME', 2, 'G1', '1111111111111'), $this->student('SAME', 3, 'G2', '2222222222222')]);
        Sanctum::actingAs($this->teacher);
    }

    private function student(string $code, int $level, string $group, ?string $citizen): Student
    {
        return new Student(code: $code, districtId: $this->district->id, districtName: 'Synthetic district', prefix: '', firstName: 'Student '.$code, lastName: $group,
            level: $level, levelLabel: 'Synthetic level', groupCode: $group, groupName: 'Group '.$group,
            enrollmentTerm: '1/2568', currentTerm: '1/2569', status: 'studying', statusLabel: 'Studying', gpax: 3, creditsEarned: 30,
            creditsRequired: 76, kpchHours: 100, moralResult: 'Passed', citizenId: $citizen);
    }

    private function roster(array $students): void
    {
        $this->currentStudents = $students;
        $repository = $this->createMock(StudentRepository::class);
        $repository->method('students')->willReturnCallback(fn () => $this->currentStudents);
        $this->app->instance(StudentRepository::class, $repository);
    }

    private function record(array $values = []): NnetResult
    {
        return NnetResult::create(array_replace(['district_id' => $this->district->id, 'academic_year' => '2569', 'round' => 1, 'education_level' => 2,
            'citizen_id' => '1111111111111', 'student_code' => 'SAME', 'student_name' => 'Own learner', 'group_code' => 'G1', 'group_name' => 'Group G1',
            'has_score' => true, 'total_score' => 40], $values));
    }

    public function test_teacher_reads_current_own_roster_and_filters_cannot_widen_scope(): void
    {
        // Deliberately misleading snapshot groups cannot establish access.
        $own = $this->record(['group_code' => 'G2', 'group_name' => 'Group G2']);
        $foreign = $this->record(['education_level' => 3, 'citizen_id' => '2222222222222', 'group_code' => 'G1', 'group_name' => 'Group G1', 'academic_year' => '2560']);
        $wrongPerson = $this->record(['citizen_id' => '3333333333333', 'group_code' => 'G1']);
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $own->id)
            ->assertJsonPath('data.available_groups', ['Group G1'])->assertJsonPath('data.available_years', ['2569']);
        $this->getJson('/api/v1/nnet/records?group=G1')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/nnet/records?group=G2')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/nnet/records?education_level=3')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/nnet/summary')->assertOk()->assertJsonPath('data.total_students', 1);
        $this->getJson('/api/v1/nnet/summary?group=G2')->assertOk()->assertJsonPath('data.total_students', 0);
        $this->getJson('/api/v1/nnet/records/'.$own->id)->assertOk();
        foreach ([$foreign, $wrongPerson] as $record) {
            $this->getJson('/api/v1/nnet/records/'.$record->id)->assertForbidden();
            $this->putJson('/api/v1/nnet/records/'.$record->id, ['total_score' => 99])->assertForbidden();
            $this->assertSame(40.0, $record->fresh()->total_score);
        }
        $this->putJson('/api/v1/nnet/records/'.$own->id, ['total_score' => 0])->assertOk()->assertJsonPath('data.total_score', 0);
    }

    public function test_teacher_without_assigned_groups_is_denied_even_for_self_created_results(): void
    {
        $record = $this->record(['created_by' => $this->teacher->id]);
        $this->teacher->forceFill(['assigned_groups' => []])->save();
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.available_groups', [])->assertJsonPath('data.available_years', []);
        $this->getJson('/api/v1/nnet/summary')->assertOk()->assertJsonPath('data.total_students', 0);
        $this->getJson('/api/v1/nnet/records/'.$record->id)->assertForbidden();
        $this->postJson('/api/v1/nnet/import', $this->importPayload([['citizen_id' => '1111111111111', 'total_score' => 99]]))->assertUnprocessable();
        $this->assertSame(40.0, $record->fresh()->total_score);
    }

    public function test_teacher_create_and_update_cannot_spoof_student_or_group(): void
    {
        $values = ['education_level' => 2, 'academic_year' => '2569', 'round' => 1, 'citizen_id' => '1111111111111'];
        foreach (['student_code' => 'OTHER', 'group_code' => 'G2', 'group_name' => 'Group G2'] as $field => $value) {
            $this->postJson('/api/v1/nnet/records', [...$values, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/v1/nnet/records', [...$values, 'education_level' => 3, 'citizen_id' => '2222222222222', 'group_code' => 'G1'])->assertUnprocessable();
        $this->postJson('/api/v1/nnet/records', [...$values, 'citizen_id' => '9999999999999'])->assertUnprocessable();
        $created = $this->postJson('/api/v1/nnet/records', $values)->assertCreated()->assertJsonPath('data.student_code', 'SAME')->assertJsonPath('data.group_code', 'G1');
        $id = $created->json('data.id');
        $this->putJson('/api/v1/nnet/records/'.$id, ['student_code' => 'OTHER'])->assertUnprocessable();
        $this->putJson('/api/v1/nnet/records/'.$id, ['group_name' => 'Group G2'])->assertUnprocessable();
        $this->assertDatabaseHas('nnet_results', ['id' => $id, 'student_code' => 'SAME', 'group_name' => 'Group G1']);
    }

    private function importPayload(array $rows): array
    {
        return ['education_level' => 2, 'academic_year' => '2569', 'round' => 1, 'rows' => $rows];
    }

    public function test_mixed_teacher_import_rejects_before_updating_any_rows(): void
    {
        $own = $this->record();
        foreach (['2222222222222', '9999999999999'] as $foreignCitizen) {
            $this->postJson('/api/v1/nnet/import', $this->importPayload([
                ['citizen_id' => '1111111111111', 'total_score' => 99],
                ['citizen_id' => $foreignCitizen, 'name' => 'Student SAME G1', 'total_score' => 99],
            ]))->assertUnprocessable()->assertJsonValidationErrors('rows.1');
            $this->assertSame(40.0, $own->fresh()->total_score);
            $this->assertDatabaseCount('nnet_results', 1);
        }
        $this->postJson('/api/v1/nnet/import', $this->importPayload([['citizen_id' => '1111111111111', 'total_score' => 0]]))
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame(0.0, $own->fresh()->total_score);
    }

    public function test_code_fallback_is_level_scoped_and_rejects_ambiguous_current_identity(): void
    {
        $this->roster([$this->student('SAME', 2, 'G1', null), $this->student('SAME', 3, 'G2', null)]);
        $own = $this->record();
        $foreign = $this->record(['education_level' => 3, 'citizen_id' => '2222222222222']);
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $own->id);
        $this->getJson('/api/v1/nnet/records/'.$foreign->id)->assertForbidden();
        $this->roster([$this->student('SAME', 2, 'G1', null), $this->student('SAME', 2, 'G2', null)]);
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/nnet/records/'.$own->id)->assertForbidden();
    }

    public function test_administrators_keep_district_wide_access_to_unmatched_records(): void
    {
        $this->record();
        $other = $this->record(['citizen_id' => '9999999999999', 'student_code' => null, 'group_code' => 'G2', 'group_name' => 'Group G2']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $this->district->id]));
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/nnet/records/'.$other->id)->assertOk();
        $this->putJson('/api/v1/nnet/records/'.$other->id, ['total_score' => 99])->assertOk();
        $this->getJson('/api/v1/nnet/summary')->assertOk()->assertJsonPath('data.total_students', 2);
    }
}
