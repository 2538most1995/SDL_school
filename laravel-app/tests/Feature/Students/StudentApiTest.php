<?php

namespace Tests\Feature\Students;

use App\Http\Controllers\Api\Students\StudentDirectoryController;
use App\Http\Controllers\Api\Students\StudentGradesController;
use App\Http\Controllers\Api\Students\StudentKpchController;
use App\Http\Controllers\Api\Students\StudentMoralController;
use App\Http\Controllers\Api\Students\StudentReportController;
use App\Http\Controllers\Api\Students\StudentSocialProfileController;
use App\Http\Controllers\Api\Students\StudentSubjectsController;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    private District $sena;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1')->middleware('auth:sanctum')->group(function (): void {
            Route::get('/students', [StudentDirectoryController::class, 'index']);
            Route::get('/students/{student}', [StudentDirectoryController::class, 'show']);
            Route::patch('/students/{student}/social', [StudentSocialProfileController::class, 'update']);
            Route::get('/students/{student}/grades', StudentGradesController::class);
            Route::get('/students/{student}/kpch', StudentKpchController::class);
            Route::get('/students/{student}/moral', StudentMoralController::class);
            Route::get('/students/{student}/subjects', StudentSubjectsController::class);
            Route::get('/reports/students/overview', [StudentReportController::class, 'overview']);
            Route::get('/reports/new-students', [StudentReportController::class, 'newStudents']);
            Route::get('/reports/graduates', [StudentReportController::class, 'graduates']);
            Route::get('/reports/transfers', [StudentReportController::class, 'transfers']);
            Route::get('/reports/registered-subjects', [StudentReportController::class, 'registeredSubjects']);
            Route::get('/reports/students/grades-above-two', [StudentReportController::class, 'gradesAboveTwo']);
            Route::get('/reports/students/exam-attendance', [StudentReportController::class, 'examAttendance']);
        });

        $this->sena = District::query()->create([
            'name' => 'อำเภอเสนา',
            'code' => 'test-sena',
            'is_active' => true,
        ]);
    }

    public function test_student_endpoints_reject_guests(): void
    {
        $this->getJson('/api/v1/students')->assertUnauthorized();
    }

    public function test_admin_can_filter_and_paginate_students_inside_own_district(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/students?level=3&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.level.id', 3)
            ->assertJsonMissingPath('data.0.status')
            ->assertJsonMissingPath('meta.filter_options.statuses')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.groups', 2)
            ->assertJsonPath('meta.source', 'synthetic_canonical_dataset')
            ->assertJsonPath('meta.contains_personal_data', false)
            ->assertJsonMissingPath('data.0.citizen_id');
    }

    public function test_super_admin_requires_an_explicit_district_context(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $this->getJson('/api/v1/students?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 8);

        Sanctum::actingAs($this->viewer('super_admin', null));
        $this->getJson('/api/v1/students?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        Sanctum::actingAs($this->viewer('super_admin', $this->sena->id));
        $this->getJson('/api/v1/students?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 8);
    }

    public function test_teacher_only_sees_assigned_groups(): void
    {
        Sanctum::actingAs($this->viewer('teacher', $this->sena->id, ['SENA-M3-B']));

        $this->getJson('/api/v1/students?per_page=50')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.group.code', 'SENA-M3-B')
            ->assertJsonPath('data.1.group.code', 'SENA-M3-B')
            ->assertJsonCount(1, 'meta.filter_options.groups')
            ->assertJsonFragment(['value' => 'เสนา ม.ปลาย B', 'label' => 'เสนา ม.ปลาย B']);

        $this->getJson('/api/v1/students?level=3&group=SENA-M3-B&per_page=50')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.levels', 1)
            ->assertJsonPath('meta.summary.groups', 1);

        $this->getJson('/api/v1/students/6650100001')->assertNotFound();
    }

    public function test_admin_can_filter_by_group_and_receives_complete_group_options(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/students?group=SENA-M3-B&per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.summary.groups', 1)
            ->assertJsonPath('data.0.group.code', 'SENA-M3-B')
            ->assertJsonFragment(['value' => 'เสนา ม.ปลาย B', 'label' => 'เสนา ม.ปลาย B']);
    }

    public function test_student_only_sees_own_record_and_private_identifiers_are_absent(): void
    {
        Sanctum::actingAs($this->viewer('student', $this->sena->id, [], '6650100001'));

        $this->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '6650100001');

        $this->getJson('/api/v1/students/6650100001')
            ->assertOk()
            ->assertJsonPath('data.data_classification', 'synthetic_demo')
            ->assertJsonMissingPath('data.citizen_id');

        $this->getJson('/api/v1/students/6650100002')->assertNotFound();
    }

    public function test_academic_endpoints_return_consistent_student_scoped_payloads(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/students/6650100001/grades?term=1/2568')
            ->assertOk()
            ->assertJsonPath('data.student.code', '6650100001')
            ->assertJsonCount(3, 'data.items')
            ->assertJsonStructure(['data' => ['summary' => [
                'gpax',
                'earned_credits',
                'compulsory_credits',
                'elective_credits',
                'graded_credits',
                'registered_subjects',
                'passed_subjects',
            ]]])
            ->assertJsonPath('data.summary.compulsory_credits', 7)
            ->assertJsonPath('data.summary.elective_credits', 0)
            ->assertJsonPath('meta.term', '1/2568');

        $this->getJson('/api/v1/students/6650100001/kpch')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.summary.total_hours', 128);

        $this->getJson('/api/v1/students/6650100001/moral')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.latest_result', 'ดีเยี่ยม');

        $this->getJson('/api/v1/students/6650100001/subjects?term=2/2568')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    public function test_reports_are_computed_from_the_same_accessible_dataset(): void
    {
        Sanctum::actingAs($this->viewer('teacher', $this->sena->id, ['SENA-M3-A']));

        $this->getJson('/api/v1/reports/students/overview')
            ->assertOk()
            ->assertJsonPath('data.totals.students', 1)
            ->assertJsonPath('data.by_level.2.students', 1);

        $this->getJson('/api/v1/reports/students/grades-above-two?term=2/2568')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonStructure(['data' => ['summary' => ['success_rate']]]);

        $this->getJson('/api/v1/reports/students/exam-attendance?term=2/2568')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonStructure(['data' => ['summary' => ['unique_students', 'attendance_rate']]]);

        $this->getJson('/api/v1/reports/students/grades-above-two?term=1/2568')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.term', '1/2568');
    }

    public function test_every_student_report_accepts_level_and_group_filters_with_teacher_scope(): void
    {
        Sanctum::actingAs($this->viewer('teacher', $this->sena->id, ['SENA-M3-B']));

        foreach ([
            '/api/v1/reports/new-students?level=3&group=SENA-M3-B',
            '/api/v1/reports/graduates?level=3&group=SENA-M3-B',
            '/api/v1/reports/transfers?level=3&group=SENA-M3-B',
            '/api/v1/reports/registered-subjects?level=3&group=SENA-M3-B&view=student',
            '/api/v1/reports/students/grades-above-two?level=3&group=SENA-M3-B&view=student',
            '/api/v1/reports/students/exam-attendance?level=3&group=SENA-M3-B&view=student',
        ] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertJsonMissing(['status' => 'กำลังศึกษา'])
                ->assertJsonMissing(['status' => 'พ้นสภาพ/รอตรวจสอบ']);
        }
    }

    public function test_academic_reports_support_student_view_level_group_and_subject_filters(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/reports/registered-subjects?term=2/2568&view=student&level=3&group=SENA-M3-B')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.student.level.id', 3)
            ->assertJsonPath('data.items.0.student.group.code', 'SENA-M3-B');

        $subjectReport = $this->getJson('/api/v1/reports/students/grades-above-two?term=2/2568&view=subject&level=3&search='.urlencode('วิทยาศาสตร์'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $subjectCode = (string) $subjectReport->json('data.items.0.subject.code');

        $this->getJson('/api/v1/reports/students/grades-above-two?term=2/2568&view=student&level=3&subject='.urlencode($subjectCode))
            ->assertOk()
            ->assertJsonPath('data.items.0.registered_subjects', 1)
            ->assertJsonStructure(['data' => ['items' => [['student' => ['code', 'full_name', 'level', 'group']]]]]);

        $this->getJson('/api/v1/reports/students/exam-attendance?term=2/2568&view=student')
            ->assertOk()
            ->assertJsonPath('data.summary.unique_students', 8)
            ->assertJsonPath('data.summary.students_attended', 8)
            ->assertJsonPath('data.summary.students_no_attendance', 0)
            ->assertJsonPath('data.summary.students_absent', 2)
            ->assertJsonPath('data.summary.students_complete', 6);
    }

    public function test_registered_subjects_default_to_latest_term_and_expose_all_term_options(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/reports/registered-subjects?view=subject')
            ->assertOk()
            ->assertJsonPath('data.selected_term', '2/2568')
            ->assertJsonPath('data.terms.0', '2/2568')
            ->assertJsonFragment(['1/2568']);

        $this->getJson('/api/v1/reports/registered-subjects?term=1/2568&view=subject')
            ->assertOk()
            ->assertJsonPath('data.selected_term', '1/2568')
            ->assertJsonPath('data.terms.0', '2/2568')
            ->assertJsonFragment(['1/2568']);
    }

    public function test_invalid_term_and_page_size_are_rejected(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/students?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 1000);
        $this->getJson('/api/v1/students?per_page=1001')->assertUnprocessable();
        $this->getJson('/api/v1/students/6650100001/grades?term=2568/2')->assertUnprocessable();
    }

    public function test_teacher_can_update_student_social_profile(): void
    {
        $teacher = $this->viewer('teacher', groups: ['SENA-P1-A']);
        Sanctum::actingAs($teacher);

        $response = $this->patchJson('/api/v1/students/6650100001/social', [
            'facebook_url' => 'https://facebook.com/nattacha.s',
            'line_id' => 'nattacha_line',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.social.facebook_url', 'https://facebook.com/nattacha.s')
            ->assertJsonPath('data.social.line_id', 'nattacha_line')
            ->assertJsonPath('data.social.line_url', 'https://line.me/ti/p/~nattacha_line');

        // Check that student listing returns the social profile
        $listResponse = $this->getJson('/api/v1/students?search=6650100001');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.social.facebook_url', 'https://facebook.com/nattacha.s')
            ->assertJsonPath('data.0.social.line_id', 'nattacha_line')
            ->assertJsonPath('data.0.social.line_url', 'https://line.me/ti/p/~nattacha_line');

        // Check that student detail returns the social profile in contact and social
        $detailResponse = $this->getJson('/api/v1/students/6650100001');
        $detailResponse->assertOk()
            ->assertJsonPath('data.contact.facebook_url', 'https://facebook.com/nattacha.s')
            ->assertJsonPath('data.contact.line_id', 'nattacha_line')
            ->assertJsonPath('data.contact.line_url', 'https://line.me/ti/p/~nattacha_line');
    }

    public function test_teacher_cannot_update_student_outside_assigned_groups(): void
    {
        $teacher = $this->viewer('teacher', groups: ['SENA-M2-A']);
        Sanctum::actingAs($teacher);

        // 6650100001 is in SENA-P1-A
        $this->patchJson('/api/v1/students/6650100001/social', [
            'facebook_url' => 'https://facebook.com/nattacha.s',
            'line_id' => 'nattacha_line',
        ])->assertNotFound();
    }

    public function test_student_can_update_own_social_profile(): void
    {
        $studentUser = $this->viewer('student', username: '6650100001');
        Sanctum::actingAs($studentUser);

        $response = $this->patchJson('/api/v1/students/6650100001/social', [
            'facebook_url' => 'https://facebook.com/student.me',
            'line_id' => 'my_own_line',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.social.facebook_url', 'https://facebook.com/student.me')
            ->assertJsonPath('data.social.line_id', 'my_own_line');

        // Check that my-learning returns the updated social info
        $myLearning = $this->getJson('/api/v1/my-learning');
        $myLearning->assertOk()
            ->assertJsonPath('data.social.facebook_url', 'https://facebook.com/student.me')
            ->assertJsonPath('data.social.line_id', 'my_own_line')
            ->assertJsonPath('data.social.line_url', 'https://line.me/ti/p/~my_own_line');
    }

    public function test_student_cannot_update_another_student_social_profile(): void
    {
        $studentUser = $this->viewer('student', username: '6650100001');
        Sanctum::actingAs($studentUser);

        // Try updating 6650200002
        $this->patchJson('/api/v1/students/6650200002/social', [
            'facebook_url' => 'https://facebook.com/hacker',
            'line_id' => 'hacker_line',
        ])->assertNotFound();
    }

    /** @param list<string> $groups */
    private function viewer(
        string $role,
        ?int $districtId = null,
        array $groups = [],
        ?string $username = null,
    ): User {
        return User::factory()->create([
            'role' => $role,
            'district_id' => $districtId ?? ($role === 'super_admin' ? null : $this->sena->id),
            'assigned_groups' => $groups,
            'username' => $username,
            'student_code' => $role === 'student' ? $username : null,
        ]);
    }
}
