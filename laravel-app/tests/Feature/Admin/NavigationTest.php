<?php

namespace Tests\Feature\Admin;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function district(string $code): District
    {
        return District::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function menuGroups(): array
    {
        return collect(config('sena.modules'))->map(fn (array $group) => ['key' => $group['key'], 'label' => $group['label'], 'items' => array_column($group['items'], 'key')])->all();
    }

    public function test_admin_can_reorder_rename_and_move_menu_without_changing_role_permissions(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $groups = array_reverse($this->menuGroups());
        $groups[0]['label'] = 'ตั้งค่าระบบใหม่';
        $groups[] = ['key' => 'custom-test', 'label' => 'หมวดหมู่ใหม่', 'items' => []];
        $groups[0]['items'] = array_reverse($groups[0]['items']);
        $groups[0]['items'][] = 'users';
        foreach ($groups as &$group) {
            if ($group['key'] === 'administration') {
                $group['items'] = array_values(array_diff($group['items'], ['users']));
            }
        }
        unset($group);
        $this->patchJson('/api/v1/admin/navigation', ['groups' => $groups])->assertOk();
        $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonPath('data.groups.0.label', 'ตั้งค่าระบบใหม่')->assertJsonPath('data.groups.0.items.0.key', 'appearance');
        $this->assertNull($other->fresh()->navigation_preferences);
        $this->assertDatabaseHas('audit_logs', ['district_id' => $district->id, 'event' => 'navigation.updated']);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/system/catalog')->assertOk();
        $this->assertFalse(collect($response->json('data.groups'))->flatMap(fn (array $group) => $group['items'])->contains('key', 'users'));
    }

    public function test_menu_management_rejects_teacher_student_and_other_district(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        foreach (['teacher', 'student'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'district_id' => $district->id]));
            $this->getJson('/api/v1/admin/navigation')->assertForbidden();
            $this->patchJson('/api/v1/admin/navigation', ['groups' => $this->menuGroups()])->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $this->withHeader('X-District-Id', (string) $other->id)->patchJson('/api/v1/admin/navigation', ['groups' => $this->menuGroups()])->assertForbidden();
        $this->getJson('/api/v1/system/catalog')->assertForbidden();
    }

    public function test_invalid_unknown_duplicate_and_missing_menus_are_rejected(): void
    {
        $district = $this->district('menu');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach (['unknown', 'duplicate', 'missing'] as $invalid) {
            $groups = $this->menuGroups();
            if ($invalid === 'unknown') {
                $groups[0]['items'][] = 'arbitrary-admin-route';
            }
            if ($invalid === 'duplicate') {
                $groups[0]['items'][] = 'dashboard';
            }
            if ($invalid === 'missing') {
                $groups[0]['items'] = [];
            }
            $this->patchJson('/api/v1/admin/navigation', ['groups' => $groups])->assertUnprocessable();
        }
        $this->assertNull($district->fresh()->navigation_preferences);
    }

    public function test_new_modules_are_appended_to_saved_categories_and_cannot_be_lost(): void
    {
        $district = $this->district('menu');
        $district->navigation_preferences = $this->menuGroups();
        $district->save();
        $modules = config('sena.modules');
        $modules[0]['items'][] = ['key' => 'new-module', 'label' => 'ใหม่', 'description' => '', 'route' => '/new', 'icon' => 'house', 'roles' => ['admin']];
        config(['sena.modules' => $modules]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/system/catalog')->assertOk();
        $this->assertTrue(collect($response->json('data.groups'))->flatMap(fn (array $group) => $group['items'])->contains('key', 'new-module'));
    }

    public function test_super_admin_must_select_active_district_and_settings_are_isolated(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->getJson('/api/v1/admin/navigation')->assertUnprocessable();
        $groups = $this->menuGroups();
        $groups[0]['label'] = 'อำเภอแรก';
        $this->withHeader('X-District-Id', (string) $district->id)->patchJson('/api/v1/admin/navigation', ['groups' => $groups])->assertOk();
        $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonPath('data.groups.0.label', 'อำเภอแรก');
        $this->withHeader('X-District-Id', (string) $other->id)->getJson('/api/v1/system/catalog')->assertOk()->assertJsonPath('data.groups.0.label', 'ภาพรวม');
    }
}
