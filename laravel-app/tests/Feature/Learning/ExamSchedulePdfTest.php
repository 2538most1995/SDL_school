<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ExamSchedulePdfTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
    }

    public function test_admin_can_download_an_mpdf_student_schedule_with_private_headers(): void
    {
        Sanctum::actingAs($this->viewer('admin'));
        $response = $this->get('/api/v1/learning/exam-schedule/pdf?scope=student&student=6650100001');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'max-age=0, no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $contentSecurityPolicy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-src 'self' blob:", $contentSecurityPolicy);
        $this->assertStringContainsString("object-src 'self' blob:", $contentSecurityPolicy);
        $this->assertStringContainsString("worker-src 'self' blob:", $contentSecurityPolicy);
        $this->assertStringContainsString("img-src 'self' data: blob:", $contentSecurityPolicy);
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertMatchesRegularExpression('/(?:attachment|inline); filename="exam-schedule-student-1\.pdf"/', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_teacher_bulk_scope_is_limited_to_assigned_groups_and_level_intersection(): void
    {
        Sanctum::actingAs($this->viewer('teacher', ['SENA-M3-A']));

        $allowed = $this->get('/api/v1/learning/exam-schedule/pdf?scope=group&group=SENA-M3-A&level=3');
        $allowed->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $allowed->getContent());
        $this->get('/api/v1/learning/exam-schedule/pdf?scope=group&group=SENA-M3-B&level=3')->assertNotFound();
        $this->get('/api/v1/learning/exam-schedule/pdf?scope=level&level=2')->assertNotFound();
    }

    public function test_student_can_export_only_self_and_cannot_use_bulk_scope(): void
    {
        Sanctum::actingAs($this->viewer('student', [], '6650100001'));

        $this->get('/api/v1/learning/exam-schedule/pdf?scope=student&student=6650100001')->assertOk();
        $this->get('/api/v1/learning/exam-schedule/pdf?scope=student&student=6650100002')->assertNotFound();
        $this->get('/api/v1/learning/exam-schedule/pdf?scope=level&level=1')->assertForbidden();
    }

    public function test_pdf_scope_requires_valid_explicit_filters(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $this->getJson('/api/v1/learning/exam-schedule/pdf')->assertUnprocessable();
        $this->getJson('/api/v1/learning/exam-schedule/pdf?scope=group&group=SENA-M3-A')->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->getJson('/api/v1/learning/exam-schedule/pdf?scope=level&level=4')->assertUnprocessable();
        $this->getJson('/api/v1/learning/exam-schedule/pdf?scope=level&level=3')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_requires_authentication_and_an_explicit_super_admin_district(): void
    {
        $path = '/api/v1/learning/exam-schedule/pdf?scope=student&student=6650100001';

        $this->get($path)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'super_admin',
            'district_id' => null,
        ]));
        $this->get($path)->assertUnprocessable();
        $this->withHeader('X-District-Id', (string) $this->district->id)
            ->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_can_get_signed_url_and_download_pdf_without_session(): void
    {
        $user = $this->viewer('admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning/exam-schedule/signed-url?scope=student&student=6650100001');
        $response->assertOk()->assertJsonStructure(['data' => ['url', 'pdf_url']]);

        $viewUrl = (string) $response->json('data.url');
        $pdfUrl = (string) $response->json('data.pdf_url');
        $this->assertNotEmpty($viewUrl);
        $this->assertNotEmpty($pdfUrl);
        $this->assertStringStartsWith('/learning/schedule/view?', $viewUrl);
        $this->assertStringStartsWith('/learning/schedule/pdf?', $pdfUrl);
        $this->assertStringContainsString('signature=', $viewUrl);
        $this->assertStringContainsString('signature=', $pdfUrl);

        // Unauthenticate and test that accessing the signed URLs succeeds without session
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $htmlResponse = $this->get($viewUrl);
        $htmlResponse->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('ตารางสอบ')
            ->assertSee('6650100001');

        $pdfResponse = $this->get($pdfUrl);
        $pdfResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', (string) $pdfResponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $pdfResponse->getContent());

        preg_match('/href="([^"]+)" class="btn btn-outline"/', (string) $htmlResponse->getContent(), $downloadLink);
        $this->assertArrayHasKey(1, $downloadLink);
        $this->get(html_entity_decode($downloadLink[1], ENT_QUOTES | ENT_HTML5))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_signed_document_urls_preserve_the_deployment_subdirectory(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->withServerVariables([
            'SENA_APP_BASE_PATH' => '/SDL_school',
        ])->getJson('/api/v1/learning/exam-schedule/signed-url?scope=student&student=6650100001');

        $response->assertOk();
        $this->assertStringStartsWith('/SDL_school/learning/schedule/view?', (string) $response->json('data.url'));
        $this->assertStringStartsWith('/SDL_school/learning/schedule/pdf?', (string) $response->json('data.pdf_url'));
    }

    public function test_html_view_shows_friendly_error_when_student_not_found(): void
    {
        $user = $this->viewer('admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning/exam-schedule/signed-url?scope=student&student=9999999999');
        $response->assertOk();

        $viewUrl = (string) $response->json('data.url');
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $htmlResponse = $this->get($viewUrl);
        $htmlResponse->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('ไม่พบข้อมูลตารางสอบ')
            ->assertSee('9999999999');
    }

    /** @param list<string> $groups */
    private function viewer(string $role, array $groups = [], ?string $username = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'district_id' => $this->district->id,
            'assigned_groups' => $groups,
            'username' => $username,
            'student_code' => $role === 'student' ? $username : null,
        ]);
    }
}
