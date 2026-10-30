<?php

namespace Tests\Feature;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Support\StudentAccessScope;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TeacherGroupIsolationTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system_data.enabled' => false, 'system_data.student_enabled' => false, 'system_data.write_enabled' => true]);
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'scope-test', 'is_active' => true]);
    }

    private function viewer(string $role, array $groups = []): User
    {
        return User::factory()->create(['role' => $role, 'district_id' => $this->district->id, 'assigned_groups' => $groups]);
    }

    public function test_teacher_report_filters_cannot_expand_the_assigned_roster(): void
    {
        Sanctum::actingAs($this->viewer('teacher', ['SENA-M3-A']));
        $this->getJson('/api/v1/students?per_page=50')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', '6650300005');
        $this->getJson('/api/v1/students?group=SENA-M3-B')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/portal')->assertOk()->assertJsonPath('data.analytics.totals.students', 1);

        $urls = ['/api/v1/reports/students/overview', '/api/v1/reports/new-students', '/api/v1/reports/graduates',
            '/api/v1/reports/expected-graduates', '/api/v1/reports/transfers', '/api/v1/reports/registered-subjects',
            '/api/v1/reports/students/registration-statistics', '/api/v1/reports/students/registration-statistics/export-data',
            '/api/v1/reports/students/grades-above-two', '/api/v1/reports/students/exam-attendance', '/api/v1/reports/students/exam-eligible',
            '/api/v1/learning/registration/workspace', '/api/v1/learning/exam-attendance/workspace'];
        foreach ($urls as $url) {
            foreach (['', '&group=SENA-M3-B'] as $filter) {
                $response = $this->getJson($url.'?term=2/2568&view=student'.$filter)->assertOk();
                $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
                foreach (['6650300006', '6650300007', '6650100001', '6650200003'] as $foreignCode) {
                    $this->assertStringNotContainsString($foreignCode, $body, $url);
                }
            }
        }
        foreach (['', '/grades', '/kpch', '/moral', '/subjects'] as $suffix) {
            $this->getJson('/api/v1/students/6650300007'.$suffix)->assertNotFound();
        }
        $this->getJson('/api/v1/students/6650300005/grades')->assertOk();

        Sanctum::actingAs($this->viewer('admin'));
        $this->getJson('/api/v1/students?group=SENA-M3-B')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_unassigned_teacher_has_no_implicit_all_group_access(): void
    {
        Sanctum::actingAs($this->viewer('teacher'));
        $this->getJson('/api/v1/students')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'meta.filter_options.groups');
        $this->getJson('/api/v1/portal')->assertOk()->assertJsonPath('data.analytics.totals.students', 0);
        $this->getJson('/api/v1/learning/classroom')->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->getJson('/api/v1/learning/classroom/tasks')->assertOk()->assertJsonCount(0, 'data.tasks');
        $this->getJson('/api/v1/reports/students/exam-eligible?term=2/2568')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_teacher_groups_use_exact_codes_or_names_without_guessing_numeric_aliases(): void
    {
        $base = (new DemoStudentRepository)->students([1])[0];
        $makeStudent = fn (string $code, string $name, int $district = 1): Student => new Student(...[...get_object_vars($base), 'districtId' => $district, 'groupCode' => $code, 'groupName' => $name]);
        $scope = StudentAccessScope::forUser($this->viewer('teacher', ['0001']));
        $this->assertTrue($scope->allows($makeStudent('0001', 'Own group')));
        $this->assertFalse($scope->allows($makeStudent('1', 'Other group')));
        $this->assertFalse($scope->allows($makeStudent('0001', 'Own group', 2)));
        $scopeByName = StudentAccessScope::forUser($this->viewer('teacher', ['Own centre']));
        $this->assertTrue($scopeByName->allows($makeStudent('220001', 'Own centre')));
        $this->assertFalse($scopeByName->allows($makeStudent('220002', 'Other centre')));
    }

    public function test_only_administrators_can_change_district_wide_nnet_schedule(): void
    {
        foreach (['teacher', 'student'] as $role) {
            Sanctum::actingAs($this->viewer($role, ['SENA-M3-A']));
            $this->putJson('/api/v1/settings/nnet-schedule', ['nnet_exam_location' => 'Unauthorized location'])->assertForbidden();
            $this->assertNull($this->district->fresh()->nnet_exam_location);
        }
        foreach (['admin', 'super_admin'] as $role) {
            Sanctum::actingAs($this->viewer($role));
            $this->withHeader('X-District-Id', (string) $this->district->id)->putJson('/api/v1/settings/nnet-schedule', ['nnet_exam_location' => 'Synthetic exam centre'])->assertOk();
            $this->assertSame('Synthetic exam centre', $this->district->fresh()->nnet_exam_location);
        }
    }
}
