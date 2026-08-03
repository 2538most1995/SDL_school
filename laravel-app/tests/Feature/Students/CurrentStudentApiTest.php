<?php

namespace Tests\Feature\Students;

use App\Models\User;
use Database\Seeders\SystemDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CurrentStudentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemDemoSeeder::class);
    }

    public function test_self_service_student_endpoints_require_authentication(): void
    {
        foreach (['/api/v1/my-learning', '/api/v1/grades', '/api/v1/kpch', '/api/v1/moral'] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
    }

    public function test_unauthenticated_api_request_returns_unauthorized_instead_of_server_error(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_student_can_read_only_scoped_synthetic_self_service_data(): void
    {
        $student = User::query()->where('username', '6650100001')->firstOrFail();

        $this->actingAs($student)
            ->getJson('/api/v1/my-learning')
            ->assertOk()
            ->assertJsonPath('data.code', '6650100001')
            ->assertJsonPath('meta.data_classification', 'synthetic_demo');

        $this->actingAs($student)
            ->getJson('/api/v1/grades?term=2/2568')
            ->assertOk()
            ->assertJsonStructure(['data' => [['code', 'subject', 'credits', 'type', 'grade', 'term']]])
            ->assertJsonPath('meta.demo', true);

        $this->actingAs($student)->getJson('/api/v1/kpch')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($student)
            ->getJson('/api/v1/moral')
            ->assertOk()
            ->assertJsonCount(11, 'data')
            ->assertJsonStructure(['data' => [['term', 'group', 'item', 'result', 'note', 'score']]]);
    }
}
