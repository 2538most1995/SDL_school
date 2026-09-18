<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Repositories\StudentRepository;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\PiiStudentRepository;
use Tests\TestCase;

final class StudentDataProxyTokenHeaderTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'sdl_student_proxy_header_test';

    protected function setUp(): void
    {
        parent::setUp();

        $district = District::query()->create([
            'name' => 'อำเภอทดสอบ',
            'code' => 'proxy-header-test',
            'is_active' => true,
        ]);
        $otherDistrict = District::query()->create([
            'name' => 'อำเภออื่น',
            'code' => 'proxy-header-other',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'username' => 'proxy.header.admin',
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
            'name' => 'proxy-header-test',
            'user_id' => $admin->id,
            'district_id' => $district->id,
            'token_hash' => hash('sha256', $this->token),
            'abilities' => ['student-data:read'],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_server_to_server_header_is_accepted_when_authorization_is_unavailable(): void
    {
        $this->withHeader('X-Student-Data-Token', $this->token)
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertOk();

        $this->withHeader('X-Student-Data-Token', 'wrong-token')
            ->getJson('/api/v1/integrations/student-data/students')
            ->assertUnauthorized();
    }
}
