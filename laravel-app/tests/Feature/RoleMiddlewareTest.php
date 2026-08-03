<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'role:admin,super_admin'])
            ->get('/api/_test/admin-only', fn () => response()->json(['data' => ['allowed' => true]]));
    }

    public function test_student_is_denied_from_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/_test/admin-only')->assertForbidden();
    }

    public function test_admin_is_allowed_on_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/_test/admin-only')
            ->assertOk()
            ->assertJsonPath('data.allowed', true);
    }
}
