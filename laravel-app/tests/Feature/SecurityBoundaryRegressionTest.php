<?php

namespace Tests\Feature;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\DemoStudentRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Services\SystemStudentAuthenticator;
use App\Models\District;
use App\Models\NnetResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SecurityBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'Synthetic district', 'code' => 'sena', 'is_active' => true]);
    }

    public function test_both_previews_bind_capabilities_to_the_effective_identity_and_bounded_expiry(): void
    {
        $viewer = $this->viewer('teacher');
        $admin = $this->viewer('admin');
        $other = District::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        Sanctum::actingAs($viewer);
        foreach (['registration', 'schedule'] as $document) {
            $response = $this->withHeader('X-District-Id', (string) $this->district->id)->get('/learning/'.$document.'/view?'.http_build_query([
                'scope' => 'student', 'student' => '6650100001', 'user_id' => $admin->id,
                'district_id' => $other->id, 'expires' => now()->addYears(10)->timestamp,
            ]));
            $response->assertOk();
            foreach (['pdfDownloadUrl', 'pdfPrintUrl'] as $key) {
                parse_str((string) parse_url($response->viewData($key), PHP_URL_QUERY), $params);
                $this->assertSame((string) $viewer->id, $params['user_id']);
                $this->assertSame((string) $this->district->id, $params['district_id']);
                $this->assertLessThanOrEqual(now()->addHours(24)->timestamp, (int) $params['expires']);
                $this->assertGreaterThan(now()->timestamp, (int) $params['expires']);
            }
        }
    }

    public function test_generated_guest_links_work_and_preview_does_not_renew_their_lifetime(): void
    {
        $user = $this->viewer('admin');
        foreach (['registration' => 'registration', 'exam-schedule' => 'schedule'] as $api => $web) {
            Sanctum::actingAs($user);
            $url = $this->getJson('/api/v1/learning/'.$api.'/signed-url?scope=student&student=6650100001')->assertOk()->json('data.url');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $original);
            auth()->forgetGuards();
            $this->travel(1)->hours();
            $preview = $this->get(str_replace('/pdf?', '/view?', $url))->assertOk();
            parse_str((string) parse_url($preview->viewData('pdfPrintUrl'), PHP_URL_QUERY), $next);
            $this->assertSame($original['expires'], $next['expires']);
            $this->get($preview->viewData('pdfPrintUrl'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->travelBack();
        }
    }

    public function test_old_signing_oracle_capabilities_are_revoked_without_rotating_the_application_key(): void
    {
        $admin = $this->viewer('admin');
        foreach (['registration' => 'course-registration:', 'schedule' => 'exam-schedule:'] as $web => $purpose) {
            $params = ['scope' => 'student', 'student' => '6650100001', 'user_id' => (string) $admin->id,
                'district_id' => (string) $this->district->id, 'expires' => (string) now()->addYears(10)->timestamp];
            ksort($params);
            $params['signature'] = hash_hmac('sha256', $purpose.http_build_query($params), (string) config('app.key'));
            $this->getJson('/learning/'.$web.'/pdf?'.http_build_query($params))->assertUnauthorized();
        }
    }

    public function test_preview_does_not_copy_array_identity_parameters_or_unknown_query_fields(): void
    {
        $viewer = $this->viewer('admin');
        Sanctum::actingAs($viewer);
        foreach (['registration', 'schedule'] as $web) {
            $response = $this->get('/learning/'.$web.'/view?'.http_build_query([
                'scope' => 'student', 'student' => '6650100001', 'user_id' => ['2'],
                'expires' => ['99999999999'], 'unexpected' => 'caller-controlled',
            ]))->assertOk();
            parse_str((string) parse_url($response->viewData('pdfPrintUrl'), PHP_URL_QUERY), $params);
            $this->assertSame((string) $viewer->id, $params['user_id']);
            $this->assertIsString($params['expires']);
            $this->assertArrayNotHasKey('unexpected', $params);
        }
    }

    public function test_disabled_retained_sessions_cannot_preview_or_download_private_documents(): void
    {
        Sanctum::actingAs($this->viewer('admin', ['disabled_at' => now()]));
        foreach (['registration', 'exam-schedule'] as $document) {
            $this->getJson('/api/v1/learning/'.$document.'/pdf?scope=student&student=6650100001')->assertUnauthorized();
        }
        $this->get('/learning/registration/view?scope=student&student=6650100001')->assertUnauthorized();
        $this->get('/learning/schedule/view?scope=student&student=6650100001')->assertUnauthorized();
    }

    public function test_student_nnet_detail_cannot_match_a_code_or_citizen_in_another_district(): void
    {
        $user = $this->viewer('student');
        $other = District::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        $own = $this->record($this->district->id, '6650100001');
        $foreign = $this->record($other->id, '6650100001');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/nnet/records/'.$own->id)->assertOk();
        $this->getJson('/api/v1/nnet/records/'.$foreign->id)->assertForbidden();
        $this->getJson('/api/v1/nnet/records/99999')->assertNotFound();
    }

    public function test_nnet_historical_code_collision_cannot_override_a_verified_citizen_identity(): void
    {
        Sanctum::actingAs($this->viewer('student', ['username' => '1111111111111']));
        $own = $this->record($this->district->id, '6650100001');
        $foreign = $this->record($this->district->id, 'OTHER', '2222222222222');
        $foreign->update(['citizen_id' => '2222222222222', 'student_code' => '6650100001', 'round' => 2]);
        $this->getJson('/api/v1/nnet/records/'.$own->id)->assertOk();
        $this->getJson('/api/v1/nnet/records/'.$foreign->id)->assertForbidden();
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_nnet_code_only_fallback_cannot_select_another_education_level(): void
    {
        Sanctum::actingAs($this->viewer('student'));
        $foreign = $this->record($this->district->id, '6650100001');
        $foreign->update(['education_level' => 3]);
        $this->getJson('/api/v1/nnet/records/'.$foreign->id)->assertForbidden();
        $this->getJson('/api/v1/nnet/records')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_ambiguous_district_code_cannot_login_or_read_selected_level(): void
    {
        $this->bindCollidingStudents();
        $this->assertNull(app(SystemStudentAuthenticator::class)->authenticate('1111111111111'));
        $this->assertSame(0, User::where('role', 'student')->count());
        $viewer = $this->viewer('student');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/students/6650100001/grades?level=1')->assertForbidden();
        $this->getJson('/api/v1/students/6650100001/grades?level=3')->assertForbidden();
        $this->getJson('/api/v1/learning/resources')->assertForbidden();
        $this->getJson('/api/v1/nnet/records')->assertForbidden();
    }

    public function test_directory_also_rejects_ambiguous_identity_without_route_middleware(): void
    {
        $this->bindCollidingStudents();
        $this->expectException(HttpException::class);
        app(StudentDirectoryService::class)->findAccessible($this->viewer('student'), '6650100001', 1);
    }

    public function test_staff_explicit_level_and_unique_student_reads_still_work(): void
    {
        $this->bindCollidingStudents();
        $admin = $this->viewer('admin');
        $this->assertSame(1, app(StudentDirectoryService::class)->findAccessible($admin, '6650100001', 1)->level);
        $this->assertSame(3, app(StudentDirectoryService::class)->findAccessible($admin, '6650100001', 3)->level);
    }

    private function viewer(string $role, array $extra = []): User
    {
        return User::factory()->create([...[
            'role' => $role, 'district_id' => $this->district->id,
            'student_code' => $role === 'student' ? '6650100001' : null,
            'assigned_groups' => ['SENA-P1-A', 'SENA-M3-A'],
        ], ...$extra]);
    }

    private function record(int $districtId, string $code, string $citizen = '1111111111111'): NnetResult
    {
        return NnetResult::create(['district_id' => $districtId, 'academic_year' => '2569', 'round' => 1,
            'education_level' => 1, 'education_level_label' => 'Synthetic level', 'citizen_id' => $citizen,
            'student_code' => $code, 'student_name' => 'Synthetic Student', 'has_score' => true, 'total_score' => 50]);
    }

    private function bindCollidingStudents(): void
    {
        $original = app(DemoStudentRepository::class)->students([$this->district->id])[0];
        $make = static function (int $level, string $citizen) use ($original): Student {
            $data = get_object_vars($original);
            $data['level'] = $level;
            $data['citizenId'] = $citizen;

            return new Student(...$data);
        };
        $students = [$make(1, '1111111111111'), $make(3, '2222222222222')];
        $repo = $this->createMock(StudentRepository::class);
        $repo->method('students')->willReturn($students);
        $repo->method('find')->willReturnCallback(static function ($code, $district = null, $level = null) use ($students) {
            $matches = array_values(array_filter($students, static fn ($s) => $s->code === $code && ($level === null || $s->level === $level)));

            return count($matches) === 1 ? $matches[0] : null;
        });
        $this->app->instance(StudentRepository::class, $repo);
    }
}
