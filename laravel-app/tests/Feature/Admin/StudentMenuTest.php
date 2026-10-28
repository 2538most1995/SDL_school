<?php

namespace Tests\Feature\Admin;

use App\Models\District;
use App\Models\User;
use App\Support\StudentDashboardMenu;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StudentMenuTest extends TestCase
{
    use RefreshDatabase;

    private function district(string $code): District
    {
        return District::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
    }

    private function items(): array
    {
        return array_map(fn (array $item): array => array_intersect_key($item, array_flip(['key', 'label', 'description', 'visible'])), app(StudentDashboardMenu::class)->defaults());
    }

    private function custom(string $key = 'custom-school', string $url = 'https://school.example.org/news?term=2', string $type = 'external'): array
    {
        return ['key' => $key, 'label' => 'ข่าวโรงเรียน', 'description' => 'เปิดข้อมูลเพิ่มเติม', 'visible' => true, 'link_type' => $type, 'url' => $url];
    }

    public function test_default_student_menu_includes_online_classroom_and_allows_only_student_modules(): void
    {
        $district = $this->district('menu');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/admin/student-menu')->assertOk()->assertJsonPath('data.can_save', true);
        $items = $response->json('data.items');
        $this->assertSame($response->json('data.defaults'), $items);
        $this->assertSame([
            'my-learning', 'learning-home', 'online-classroom', 'assignments', 'resources', 'public-relations', 'schedule', 'exam-attendance-check', 'nnet-report',
        ], array_column(array_filter($items, fn (array $item): bool => $item['visible']), 'key'));
        $allowed = collect(config('sena.modules'))->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item): bool => in_array('student', $item['roles'], true) && $item['key'] !== 'dashboard')->pluck('key')->sort()->values()->all();
        $this->assertSame($allowed, collect($items)->pluck('key')->sort()->values()->all());
        $this->assertSame('ข้อมูลส่วนตัว', $items[0]['label']);
    }

    public function test_admin_can_save_order_labels_descriptions_visibility_and_student_sees_only_visible_safe_buttons(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $items = array_reverse($this->items());
        foreach ($items as &$item) {
            $item['visible'] = false;
        }
        unset($item);
        $items[0]['visible'] = true;
        $items[0]['label'] = '  ปรับรูปแบบของฉัน  ';
        $items[0]['description'] = '  เลือกสีและขนาดข้อความ  ';
        $this->patchJson('/api/v1/admin/student-menu', ['items' => $items])->assertOk()
            ->assertJsonPath('data.items.0.label', 'ปรับรูปแบบของฉัน')->assertJsonPath('data.items.0.description', 'เลือกสีและขนาดข้อความ');
        $this->getJson('/api/v1/admin/student-menu')->assertOk()->assertJsonPath('data.items.0.key', $items[0]['key']);
        $this->assertNull($other->fresh()->student_menu_preferences);
        $this->assertNull($district->fresh()->navigation_preferences);
        $this->assertDatabaseHas('audit_logs', ['district_id' => $district->id, 'event' => 'student-menu.updated']);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonCount(1, 'data.student_menu')
            ->assertJsonPath('data.student_menu.0.key', $items[0]['key'])
            ->assertJsonPath('data.student_menu.0.label', 'ปรับรูปแบบของฉัน');
        $this->assertSame('/settings/appearance', $response->json('data.student_menu.0.route'));
        $this->assertFalse(collect($response->json('data.groups'))->flatMap(fn (array $group) => $group['items'])->contains('key', 'student-menu'));
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $other->id]));
        $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonCount(9, 'data.student_menu')
            ->assertJsonPath('data.student_menu.0.label', 'ข้อมูลส่วนตัว');
    }

    public function test_student_menu_management_rejects_guests_teachers_students_and_other_district(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        $this->getJson('/api/v1/admin/student-menu')->assertUnauthorized();
        $this->patchJson('/api/v1/admin/student-menu', ['items' => $this->items()])->assertUnauthorized();
        foreach (['teacher', 'student'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'district_id' => $district->id]));
            $this->getJson('/api/v1/admin/student-menu')->assertForbidden();
            $this->patchJson('/api/v1/admin/student-menu', ['items' => $this->items()])->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $this->withHeader('X-District-Id', (string) $other->id)->getJson('/api/v1/admin/student-menu')->assertForbidden();
        $this->patchJson('/api/v1/admin/student-menu', ['items' => $this->items()])->assertForbidden();
        $this->assertNull($district->fresh()->student_menu_preferences);
        $this->assertNull($other->fresh()->student_menu_preferences);
    }

    public function test_invalid_unknown_duplicate_missing_and_privileged_fields_are_rejected(): void
    {
        $district = $this->district('menu');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach (['unknown', 'duplicate', 'missing', 'route', 'icon', 'roles', 'tone', 'custom', 'external', 'url', 'link_type', 'blank', 'label-long', 'description-long', 'visible-string', 'missing-visible'] as $invalid) {
            $items = $this->items();
            switch ($invalid) {
                case 'unknown': $items[0]['key'] = 'users';
                    break;
                case 'duplicate': $items[0]['key'] = $items[1]['key'];
                    break;
                case 'missing': array_pop($items);
                    break;
                case 'blank': $items[0]['label'] = '   ';
                    break;
                case 'label-long': $items[0]['label'] = str_repeat('ก', 81);
                    break;
                case 'description-long': $items[0]['description'] = str_repeat('ก', 161);
                    break;
                case 'visible-string': $items[0]['visible'] = 'true';
                    break;
                case 'missing-visible': unset($items[0]['visible']);
                    break;
                default: $items[0][$invalid] = '/admin/users';
                    break;
            }
            $this->patchJson('/api/v1/admin/student-menu', ['items' => $items])->assertUnprocessable();
        }
        $this->assertNull($district->fresh()->student_menu_preferences);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'student-menu.updated']);
    }

    public function test_super_admin_must_select_active_district_and_cannot_change_another_selected_district(): void
    {
        $district = $this->district('menu');
        $other = $this->district('other');
        $inactive = $this->district('inactive');
        $inactive->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));
        $this->getJson('/api/v1/admin/student-menu')->assertUnprocessable();
        $this->withHeader('X-District-Id', (string) $inactive->id)->getJson('/api/v1/admin/student-menu')->assertNotFound();
        $items = $this->items();
        $items[0]['label'] = 'อำเภอแรก';
        $this->withHeader('X-District-Id', (string) $district->id)->patchJson('/api/v1/admin/student-menu', ['items' => $items, 'district_id' => $other->id])->assertOk();
        $this->assertSame('อำเภอแรก', $district->fresh()->student_menu_preferences[0]['label']);
        $this->assertNull($other->fresh()->student_menu_preferences);
        $this->withHeader('X-District-Id', (string) $other->id)->getJson('/api/v1/admin/student-menu')->assertOk()->assertJsonPath('data.items.0.label', 'ข้อมูลส่วนตัว');
    }

    public function test_saved_preferences_cannot_override_route_icon_tone_or_add_admin_modules_and_new_student_modules_append(): void
    {
        $district = $this->district('menu');
        $district->student_menu_preferences = [
            ['key' => 'users', 'label' => 'แอดมิน', 'visible' => true],
            ['key' => 'my-learning', 'label' => 'ของฉัน', 'description' => '', 'visible' => true, 'route' => '/admin/users', 'icon' => 'evil', 'tone' => 'evil'],
            ['key' => 'my-learning', 'label' => 'ซ้ำ', 'visible' => true],
        ];
        $district->save();
        $modules = config('sena.modules');
        $modules[0]['items'][] = ['key' => 'new-student-module', 'label' => 'ใหม่', 'description' => '', 'route' => '/learning/new', 'icon' => 'house', 'roles' => ['student']];
        config(['sena.modules' => $modules]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/admin/student-menu')->assertOk()
            ->assertJsonPath('data.items.0.key', 'my-learning')->assertJsonPath('data.items.0.label', 'ของฉัน')
            ->assertJsonPath('data.items.0.route', '/my-learning')->assertJsonPath('data.items.0.icon', 'student')->assertJsonPath('data.items.0.tone', 'blue');
        $resolved = collect($response->json('data.items'));
        $this->assertFalse($resolved->contains('key', 'users'));
        $this->assertSame(1, $resolved->where('key', 'my-learning')->count());
        $this->assertNotNull($resolved->firstWhere('key', 'new-student-module'));
        $this->assertFalse($resolved->firstWhere('key', 'new-student-module')['visible']);
    }

    public function test_before_migration_defaults_are_readable_but_saving_is_unavailable(): void
    {
        Schema::table('districts', fn (Blueprint $table) => $table->dropColumn('student_menu_preferences'));
        $district = $this->district('menu');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $this->getJson('/api/v1/admin/student-menu')->assertOk()->assertJsonPath('data.can_save', false)->assertJsonPath('data.items.0.key', 'my-learning');
        $this->patchJson('/api/v1/admin/student-menu', ['items' => $this->items()])->assertStatus(503);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $district->id]));
        $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonCount(9, 'data.student_menu');
    }

    public function test_migration_can_run_again_without_overwriting_preferences(): void
    {
        $district = $this->district('menu');
        $district->student_menu_preferences = $this->items();
        $district->save();
        $migration = require database_path('migrations/2026_10_15_000033_add_student_menu_preferences_to_districts.php');
        $migration->up();
        $migration->up();
        $this->assertSame($this->items(), $district->fresh()->student_menu_preferences);
    }

    public function test_admin_can_add_mixed_internal_external_links_reorder_hide_and_delete_them_with_district_isolation(): void
    {
        $district = $this->district('custom');
        $other = $this->district('other-custom');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $defaults = $this->items();
        $hidden = [...$this->custom('custom-hidden'), 'visible' => false];
        $items = [$this->custom('custom-home', '/app', 'internal'), $defaults[0], $this->custom(), ...array_slice($defaults, 1), $hidden];
        $response = $this->patchJson('/api/v1/admin/student-menu', ['items' => $items])->assertOk()
            ->assertJsonPath('data.items.0.key', 'custom-home')->assertJsonPath('data.items.0.route', '/app')
            ->assertJsonPath('data.items.0.custom', true)->assertJsonPath('data.items.0.external', false)
            ->assertJsonPath('data.items.1.custom', false)->assertJsonPath('data.items.1.external', false)
            ->assertJsonPath('data.items.2.key', 'custom-school')->assertJsonPath('data.items.2.external', true)
            ->assertJsonPath('data.items.2.icon', 'link')->assertJsonPath('data.items.2.tone', 'blue');
        $routes = collect($response->json('data.internal_routes'));
        $this->assertTrue($routes->contains('route', '/app'));
        $this->assertFalse($routes->contains('route', '/admin/users'));
        $this->getJson('/api/v1/admin/student-menu')->assertOk()->assertJsonPath('data.items.2.url', $items[2]['url']);
        $this->assertNull($other->fresh()->student_menu_preferences);
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $district->id]));
        $student = $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonPath('data.student_menu.0.key', 'custom-home')
            ->assertJsonPath('data.student_menu.2.key', 'custom-school')->assertJsonPath('data.student_menu.2.external', true);
        $this->assertFalse(collect($student->json('data.student_menu'))->contains('key', 'custom-hidden'));
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $other->id]));
        $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonCount(9, 'data.student_menu');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $this->patchJson('/api/v1/admin/student-menu', ['items' => [$defaults[0], $this->custom(), ...array_slice($defaults, 1)]])->assertOk()
            ->assertJsonPath('data.items.1.key', 'custom-school');
        $this->assertFalse(collect($district->fresh()->student_menu_preferences)->contains('key', 'custom-home'));
        $this->patchJson('/api/v1/admin/student-menu', ['items' => $defaults])->assertOk()->assertJsonCount(count($defaults), 'data.items');
    }

    public function test_custom_internal_links_reject_staff_routes_and_non_catalog_destinations(): void
    {
        $district = $this->district('custom');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach (['/admin/users', '/super-admin/districts', '/reports/registered-subjects', '/learning/lesson-plans', '//school.example.org', '/app?role=admin', '/app#section', '/settings/../admin/users', 'https://school.example.org'] as $url) {
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom(url: $url, type: 'internal')]])->assertUnprocessable();
        }
        $this->assertNull($district->fresh()->student_menu_preferences);
    }

    public function test_custom_external_links_reject_unsafe_schemes_credentials_whitespace_and_malformed_urls(): void
    {
        $district = $this->district('custom');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach ([
            'javascript:alert(1)', 'data:text/html,test', 'ftp://school.example.org', '//school.example.org',
            '/learning', 'https:school.example.org', 'https:///school.example.org', 'https://',
            'https://user:pass@school.example.org', 'https://user@school.example.org', 'https://@school.example.org',
            'https://school.example.org\\@evil.example.org', "https://school.example.org/a\nb", "https://school.example.org/a\tb",
            'https://school.example.org/a b', "https://school.example.org/a\u{00A0}b", 'https://school.example.org:99999',
            'https://school.example.org/%0a', 'https://school.example.org/%00',
            'https://school.example.org/%GG', 'https://school.example.org/'.str_repeat('a', 2048),
        ] as $url) {
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom(url: $url)]])->assertUnprocessable();
        }
        $this->assertNull($district->fresh()->student_menu_preferences);
        foreach (['http://school.example.org/path', 'https://school.example.org/path?q=school%20news#latest'] as $url) {
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom(url: $url)]])->assertOk();
        }
    }

    public function test_custom_links_require_complete_fields_unique_keys_and_at_most_fifty_entries(): void
    {
        $district = $this->district('custom');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        foreach (['url', 'link_type', 'description', 'visible'] as $field) {
            $custom = $this->custom();
            unset($custom[$field]);
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $custom]])->assertUnprocessable();
        }
        foreach (['custom-', 'custom-UPPER', 'custom-'.str_repeat('a', 65), 'my-own-key'] as $key) {
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom(key: $key)]])->assertUnprocessable();
        }
        $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom(), $this->custom()]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/student-menu', ['items' => [...array_slice($this->items(), 1), $this->custom()]])->assertUnprocessable();
        foreach (['route', 'icon', 'roles', 'tone', 'custom', 'external'] as $field) {
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), [...$this->custom(), $field => 'unsafe']]])->assertUnprocessable();
        }
        $customs = array_map(fn (int $index): array => $this->custom('custom-'.$index), range(1, 50));
        $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), ...$customs, $this->custom('custom-51')]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), ...$customs]])->assertOk();
        $this->assertCount(count($this->items()) + 50, $district->fresh()->student_menu_preferences);
    }

    public function test_stored_custom_links_are_revalidated_before_student_display_and_admin_reads(): void
    {
        $district = $this->district('custom');
        $district->student_menu_preferences = [
            $this->custom('custom-good'),
            $this->custom('custom-bad-scheme', 'javascript:alert(1)'),
            $this->custom('custom-admin', '/admin/users', 'internal'),
            [...$this->custom('custom-hidden'), 'visible' => false],
            [...$this->custom('custom-invalid-label'), 'label' => ''],
            [...$this->custom('custom-invalid-visible'), 'visible' => 'true'],
            [...$this->custom('custom-tampered'), 'route' => 'javascript:alert(1)', 'icon' => 'evil', 'tone' => 'evil', 'external' => false],
            $this->custom('custom-good', 'https://duplicate.example.org'),
        ];
        $district->save();
        Sanctum::actingAs(User::factory()->create(['role' => 'student', 'district_id' => $district->id]));
        $response = $this->getJson('/api/v1/system/catalog')->assertOk()->assertJsonCount(11, 'data.student_menu')
            ->assertJsonPath('data.student_menu.0.key', 'custom-good')
            ->assertJsonPath('data.student_menu.1.key', 'custom-tampered')->assertJsonPath('data.student_menu.1.external', true)
            ->assertJsonPath('data.student_menu.1.route', $this->custom()['url'])->assertJsonPath('data.student_menu.1.icon', 'link');
        $this->assertSame(1, collect($response->json('data.student_menu'))->where('key', 'custom-good')->count());
        $this->withHeader('X-District-Id', (string) $this->district('other')->id)->getJson('/api/v1/system/catalog')->assertForbidden();
    }

    public function test_custom_link_write_requires_admin_role_in_own_district(): void
    {
        $district = $this->district('custom');
        $other = $this->district('custom-other');
        foreach (['teacher', 'student'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'district_id' => $district->id]));
            $this->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom()]])->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));
        $this->withHeader('X-District-Id', (string) $other->id)->patchJson('/api/v1/admin/student-menu', ['items' => [...$this->items(), $this->custom()]])->assertForbidden();
        $this->assertNull($district->fresh()->student_menu_preferences);
        $this->assertNull($other->fresh()->student_menu_preferences);
    }
}
