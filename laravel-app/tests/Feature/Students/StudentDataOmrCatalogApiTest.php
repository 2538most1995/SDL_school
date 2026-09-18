<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\PiiStudentRepository;
use Tests\TestCase;

final class StudentDataOmrCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'sdl_student_omr_catalog_test';

    protected function setUp(): void
    {
        parent::setUp();

        $district = District::query()->create([
            'name' => 'อำเภอทดสอบ',
            'code' => 'omr-test',
            'is_active' => true,
        ]);
        $otherDistrict = District::query()->create([
            'name' => 'อำเภออื่น',
            'code' => 'omr-other',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'username' => 'omr.admin',
            'role' => 'admin',
            'district_id' => $district->id,
            'assigned_groups' => [],
            'disabled_at' => null,
        ]);
        $this->app->scoped(
            StudentRepository::class,
            fn (): StudentRepository => new PiiStudentRepository($district->id, $district->name, $otherDistrict->id),
        );
        StudentApiClient::query()->create([
            'name' => 'omr-catalog-test',
            'user_id' => $admin->id,
            'district_id' => $district->id,
            'token_hash' => hash('sha256', $this->token),
            'abilities' => ['student-data:read'],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_omr_catalog_exposes_subjects_groups_and_safe_roster(): void
    {
        $headers = ['Authorization' => "Bearer {$this->token}"];

        $this->withHeaders($headers)
            ->getJson('/api/v1/integrations/student-data/subjects?term=1/2569')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'TH1001')
            ->assertJsonPath('data.0.name', 'ภาษาไทย')
            ->assertJsonPath('data.0.student_count', 2)
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($headers)
            ->getJson('/api/v1/integrations/student-data/subjects/TH1001/class-groups?term=1/2569')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'GROUP-A')
            ->assertJsonPath('data.0.student_count', 2);

        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/integrations/student-data/class-groups/GROUP-A/students?term=1/2569&subject_code=TH1001')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'STU001')
            ->assertJsonPath('data.1.code', 'STU002')
            ->assertJsonPath('meta.total', 2);

        self::assertStringNotContainsString('citizen_id', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_omr_catalog_stays_protected_and_validates_filters(): void
    {
        $this->getJson('/api/v1/integrations/student-data/subjects')->assertUnauthorized();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/subjects?term=3/2569')
            ->assertUnprocessable();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/class-groups/GROUP-A/students?term=1/2569')
            ->assertUnprocessable();
    }
}
