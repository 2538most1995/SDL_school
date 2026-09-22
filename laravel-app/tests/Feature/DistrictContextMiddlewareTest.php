<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistrictContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'district'])
            ->get('/api/_test/district', fn (Request $request) => [
                'data' => ['district_id' => $request->attributes->get('district_id')],
            ]);
    }

    public function test_admin_is_locked_to_own_district(): void
    {
        $own = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        $other = District::create(['name' => 'อำเภอบางซ้าย', 'code' => 'bang-sai']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $own->id]));

        $this->getJson('/api/_test/district')
            ->assertOk()
            ->assertJsonPath('data.district_id', $own->id);

        $this->withHeader('X-District-Id', (string) $other->id)
            ->getJson('/api/_test/district')
            ->assertForbidden();
    }

    public function test_super_admin_must_explicitly_choose_an_active_district(): void
    {
        $district = District::create(['name' => 'อำเภอเสนา', 'code' => 'sena']);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->getJson('/api/_test/district')->assertUnprocessable();

        $this->withHeader('X-District-Id', (string) $district->id)
            ->getJson('/api/_test/district')
            ->assertOk()
            ->assertJsonPath('data.district_id', $district->id);
    }
}
