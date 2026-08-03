<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_catalog_excludes_administration(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $response = $this->getJson('/api/v1/system/catalog')
            ->assertOk()
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('meta.contains_personal_data', false);

        $keys = collect($response->json('data.groups'))->pluck('key');

        $this->assertFalse($keys->contains('administration'));
        $this->assertTrue($keys->contains('learning'));
        $this->assertTrue($keys->contains('basic-information'));
        $this->assertFalse($keys->contains('students'));
        $this->assertFalse($keys->contains('academic-results'));
        $this->assertFalse($keys->contains('student-development'));

        $basicItems = collect($response->json('data.groups'))
            ->firstWhere('key', 'basic-information')['items'];
        $this->assertSame(['my-learning', 'grades', 'kpch', 'moral'], collect($basicItems)->pluck('key')->values()->all());
    }

    public function test_admin_catalog_includes_administration_and_own_district_branding(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/v1/system/catalog')->assertOk();
        $items = collect($response->json('data.groups'))->flatMap(fn (array $group) => $group['items']);

        $this->assertTrue($items->contains('key', 'users'));
        $this->assertTrue($items->contains('key', 'branding'));
    }

    public function test_query_string_cannot_escalate_catalog_role(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $this->getJson('/api/v1/system/catalog?role=super_admin')
            ->assertOk()
            ->assertJsonPath('data.role', 'student')
            ->assertJsonMissing(['key' => 'administration']);
    }
}
