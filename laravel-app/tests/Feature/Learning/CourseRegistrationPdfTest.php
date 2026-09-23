<?php

namespace Tests\Feature\Learning;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourseRegistrationPdfTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();
        $this->district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena', 'is_active' => true]);
    }

    public function test_admin_can_download_student_registration_pdf(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        $response = $this->get('/api/v1/learning/registration/pdf?scope=student&student=6650100001');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'max-age=0, no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertMatchesRegularExpression('/(?:attachment|inline); filename="course-registration-student-1\.pdf"/', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_user_can_download_blank_template_for_all_three_levels(): void
    {
        Sanctum::actingAs($this->viewer('admin'));

        // Level 1: ประถมศึกษา
        $p1 = $this->get('/api/v1/learning/registration/pdf?scope=blank&level=1');
        $p1->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $p1->getContent());

        // Level 2: มัธยมศึกษาตอนต้น
        $m2 = $this->get('/api/v1/learning/registration/pdf?scope=blank&level=2');
        $m2->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $m2->getContent());

        // Level 3: มัธยมศึกษาตอนปลาย
        $m3 = $this->get('/api/v1/learning/registration/pdf?scope=blank&level=3');
        $m3->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $m3->getContent());
    }

    public function test_user_can_get_signed_url_and_access_pdf_without_session(): void
    {
        $user = $this->viewer('admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/learning/registration/signed-url?scope=student&student=6650100001');
        $response->assertOk()->assertJsonStructure(['data' => ['url', 'pdf_url']]);

        $inlinePdfUrl = (string) $response->json('data.url');
        $this->assertNotEmpty($inlinePdfUrl);
        $this->assertStringStartsWith('/learning/registration/pdf?', $inlinePdfUrl);
        $this->assertStringContainsString('signature=', $inlinePdfUrl);

        // Unauthenticate and test access with signature
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $pdfResponse = $this->get($inlinePdfUrl);
        $pdfResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdfResponse->getContent());
    }

    public function test_html_preview_view_contains_registration_form_elements(): void
    {
        $user = $this->viewer('admin');
        Sanctum::actingAs($user);

        $response = $this->get('/learning/registration/view?scope=student&student=6650100001');
        $response->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringContainsString('ใบลงทะเบียน', $content);
        $this->assertStringContainsString('สาระการเรียนรู้', $content);
        $this->assertStringContainsString('รายวิชาบังคับ', $content);
        $this->assertStringContainsString('รายวิชาเลือก', $content);
        $this->assertStringContainsString('รหัสประจำตัวประชาชน', $content);
        $this->assertStringContainsString('รหัสประจำตัวนักศึกษา', $content);
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
