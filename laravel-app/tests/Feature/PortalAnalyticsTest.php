<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PortalAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_analytics_are_complete_and_use_one_scoped_dataset(): void
    {
        $district = District::query()->create([
            'id' => 1,
            'name' => 'อำเภอเสนา',
            'code' => 'portal-sena',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'admin',
            'district_id' => $district->id,
            'assigned_groups' => [],
        ]);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.analytics.totals.students', 8)
            ->assertJsonPath('data.analytics.totals.groups', 5)
            ->assertJsonPath('data.analytics.totals.new_students', 0)
            ->assertJsonPath('data.analytics.current_term', '2/2568')
            ->assertJsonMissingPath('data.analytics.by_status')
            ->assertJsonCount(3, 'data.analytics.by_level')
            ->assertJsonCount(3, 'data.analytics.by_gender')
            ->assertJsonCount(5, 'data.analytics.by_group')
            ->assertJsonCount(5, 'data.analytics.moral')
            ->assertJsonStructure(['data' => ['analytics' => ['averages' => [
                'gpax',
                'credits_earned',
                'credits_required',
                'credit_progress_percent',
                'kpch_hours',
            ]]]]);

        $levelTotal = collect($response->json('data.analytics.by_level'))->sum('value');
        $moralTotal = collect($response->json('data.analytics.moral'))->sum('value');

        $this->assertSame(8, $levelTotal);
        $this->assertSame(8, $moralTotal);
    }

    public function test_teacher_dashboard_only_summarizes_assigned_groups(): void
    {
        $district = District::query()->create([
            'id' => 1,
            'name' => 'อำเภอเสนา',
            'code' => 'portal-teacher-sena',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $district->id,
            'assigned_groups' => ['SENA-M3-B'],
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.analytics.totals.students', 2)
            ->assertJsonPath('data.analytics.totals.groups', 1)
            ->assertJsonPath('data.analytics.by_group.0.label', 'เสนา ม.ปลาย B')
            ->assertJsonPath('data.analytics.by_group.0.value', 2);
    }
}
