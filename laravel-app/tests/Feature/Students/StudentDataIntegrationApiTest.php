<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\PiiStudentRepository;
use Tests\TestCase;

final class StudentDataIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private District $otherDistrict;

    private User $admin;

    private string $token = 'sdl_student_valid_test_token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->district = District::query()->create([
            'name' => 'อำเภอทดสอบ',
            'code' => 'integration-test',
            'is_active' => true,
        ]);
        $this->otherDistrict = District::query()->create([
            'name' => 'อำเภออื่น',
            'code' => 'other-test',
            'is_active' => true,
        ]);
        $this->admin = User::factory()->create([
            'username' => 'integration.admin',
            'role' => 'admin',
            'district_id' => $this->district->id,
            'assigned_groups' => [],
            'disabled_at' => null,
        ]);
        $this->app->scoped(
            StudentRepository::class,
            fn (): StudentRepository => new PiiStudentRepository(
                $this->district->id,
                $this->district->name,
                $this->otherDistrict->id,
            ),
        );
        $this->client($this->token);
    }

    public function test_api_requires_a_dedicated_valid_bearer_token(): void
    {
        $this->getJson('/api/v1/integrations/student-data/students')->assertUnauthorized();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();

        $this->withToken('wrong-token')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();

        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertOk();
    }

    public function test_student_payload_is_allowlisted_and_never_contains_sensitive_profile_data(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students/STU001')
            ->assertOk()
            ->assertHeader('Vary', 'Authorization')
            ->assertJsonPath('data.code', 'STU001')
            ->assertJsonPath('data.status.code', 'studying')
            ->assertJsonPath('meta.data_classification', 'personal_educational_data')
            ->assertJsonPath('meta.sensitive_identifiers_included', false);

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNoSensitiveData($response->json());
    }

    public function test_every_academic_endpoint_uses_the_same_safe_student_boundary(): void
    {
        foreach (['grades', 'kpch', 'moral', 'subjects'] as $dataset) {
            $response = $this->withToken($this->token)
                ->getJson("/api/v1/integrations/student-data/students/STU001/{$dataset}?term=1/2569")
                ->assertOk()
                ->assertJsonPath('data.student.code', 'STU001')
                ->assertJsonCount(1, 'data.items');

            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertNoSensitiveData($response->json());
        }

        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students/STU001/grades?per_page=501')
            ->assertUnprocessable();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students/STU001/grades?page=999')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('meta.pagination.current_page', 999);
    }

    public function test_registered_subjects_expose_the_subject_code_and_name_contract(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students/STU001/subjects?term=1/2569&page=1&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.items.0.student_code', 'STU001')
            ->assertJsonPath('data.items.0.code', 'TH1001')
            ->assertJsonPath('data.items.0.name', 'ภาษาไทย')
            ->assertJsonPath('data.items.0.credits', 3)
            ->assertJsonPath('data.items.0.type', 'compulsory')
            ->assertJsonPath('data.items.0.term', '1/2569')
            ->assertJsonPath('data.items.0.registration_status', 'passed')
            ->assertJsonPath('data.items.0.is_transferred', false)
            ->assertJsonPath('data.items.0.grade', '3.5')
            ->assertJsonPath('data.items.0.exam_attended', true)
            ->assertJsonPath('data.summary.subject_count', 1)
            ->assertJsonPath('meta.term', '1/2569')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_client_is_fixed_to_one_district_and_cannot_override_it(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students/OTHER001')
            ->assertNotFound();

        $this->withToken($this->token)
            ->withHeader('X-District-Id', (string) $this->otherDistrict->id)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnprocessable();

        $this->withToken($this->token)
            ->getJson("/api/v1/integrations/student-data/students?district_id={$this->otherDistrict->id}")
            ->assertUnprocessable();
    }

    public function test_browser_origin_requests_are_rejected_to_protect_the_bearer_secret(): void
    {
        $this->withToken($this->token)
            ->withHeader('Origin', 'https://partner.example.test')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertForbidden();
    }

    public function test_search_does_not_use_citizen_id_and_pagination_is_bounded(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?search=1111111111111')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonMissingPath('meta.applied_filters');
        $this->assertNoSensitiveData($response->json());

        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?page=999&per_page=1')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.current_page', 999)
            ->assertJsonPath('meta.pagination.from', null);

        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?per_page=101')
            ->assertUnprocessable();
    }

    public function test_revoked_expired_or_wrong_ability_clients_are_rejected(): void
    {
        $this->client('revoked', ['student-data:read'], revoked: true);
        $this->client('expired', ['student-data:read'], expired: true);
        $this->client('wrong-ability', ['other:read']);

        foreach (['revoked', 'expired', 'wrong-ability'] as $token) {
            $this->withToken($token)
                ->getJson('/api/v1/integrations/student-data/students')
                ->assertUnauthorized();
        }
    }

    public function test_invalid_credentials_are_rate_limited_by_ip_before_database_authentication(): void
    {
        config(['system_data.student_api_auth_rate_limit_per_minute' => 2]);

        $this->withToken('invalid-one')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();
        $this->withToken('invalid-two')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();
        $this->withToken('invalid-three')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_disabled_owner_inactive_district_and_admin_scope_mismatch_are_rejected(): void
    {
        $this->admin->forceFill(['disabled_at' => now()])->save();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();

        $this->admin->forceFill(['disabled_at' => null])->save();
        $this->district->forceFill(['is_active' => false])->save();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();

        $this->district->forceFill(['is_active' => true])->save();
        $this->admin->forceFill(['district_id' => $this->otherDistrict->id])->save();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();
    }

    public function test_integration_token_cannot_authenticate_internal_admin_routes(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/admin/imports')
            ->assertUnauthorized();
    }

    public function test_integration_namespace_is_read_only(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/integrations/student-data/students', ['code' => 'NEW'])
            ->assertMethodNotAllowed();

        $this->withToken($this->token)
            ->patchJson('/api/v1/integrations/student-data/students/STU001', ['name' => 'changed'])
            ->assertMethodNotAllowed();

        $this->withToken($this->token)
            ->deleteJson('/api/v1/integrations/student-data/students/STU001')
            ->assertMethodNotAllowed();
    }

    public function test_rate_limit_is_applied_per_api_client(): void
    {
        config(['system_data.student_api_rate_limit_per_minute' => 2]);
        $secondToken = 'sdl_student_second_client';
        $this->client($secondToken);

        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?per_page=1')
            ->assertOk();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?per_page=1')
            ->assertOk();
        $this->withToken($this->token)
            ->getJson('/api/v1/integrations/student-data/students?per_page=1')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->withToken($secondToken)
            ->getJson('/api/v1/integrations/student-data/students?per_page=1')
            ->assertOk();
    }

    /** @param list<string> $abilities */
    private function client(
        string $plainToken,
        array $abilities = ['student-data:read'],
        bool $revoked = false,
        bool $expired = false,
    ): StudentApiClient {
        return StudentApiClient::query()->create([
            'name' => 'test-client-'.hash('crc32b', $plainToken),
            'user_id' => $this->admin->id,
            'district_id' => $this->district->id,
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => $abilities,
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    private function assertNoSensitiveData(mixed $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([
            'citizen_id',
            'citizen_id_masked',
            '1111111111111',
            '1-xxxx-xxxxx-xx-1',
            'registered_address',
            'current_address',
            'ที่อยู่ทะเบียนลับ',
            'ที่อยู่ปัจจุบันลับ',
            '0899999999',
            'ผู้ปกครองลับ',
            'private-student',
            'private-line',
            'birth_date',
        ] as $sensitiveValue) {
            self::assertStringNotContainsString($sensitiveValue, $encoded);
        }
    }
}
