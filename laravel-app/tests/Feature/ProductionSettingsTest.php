<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_override_is_stored_locally_and_contact_email_is_encrypted(): void
    {
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'test']);
        $user = User::factory()->create(['role' => 'teacher', 'district_id' => $district->id, 'auth_source' => 'local']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/settings/profile', [
            'display_name' => 'ชื่อที่เลือกแสดง',
            'email' => 'viewer@example.test',
        ])->assertOk()
            ->assertJsonPath('data.displayName', 'ชื่อที่เลือกแสดง')
            ->assertJsonPath('data.email', 'viewer@example.test')
            ->assertJsonPath('data.canChangePassword', true);

        $stored = DB::table('users')->where('id', $user->id)->value('contact_email');
        $this->assertNotSame('viewer@example.test', $stored);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'profile.updated']);
    }

    public function test_appearance_is_persisted_per_user(): void
    {
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'test']);
        $user = User::factory()->create(['role' => 'admin', 'district_id' => $district->id]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/settings/appearance', [
            'theme' => 'dark',
            'colorScheme' => 'violet',
            'fontSize' => 'large',
            'density' => 'compact',
        ])->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.colorScheme', 'violet')
            ->assertJsonPath('data.fontSize', 'large')
            ->assertJsonPath('data.density', 'compact');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'color_scheme' => 'violet']);
    }

    public function test_local_user_can_change_password(): void
    {
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'password']);
        $user = User::factory()->create([
            'role' => 'teacher',
            'district_id' => $district->id,
            'auth_source' => 'local',
            'password' => Hash::make('Current123'),
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/settings/password', [
            'current_password' => 'incorrect',
            'password' => 'NewSecure456',
            'password_confirmation' => 'NewSecure456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/v1/settings/password', [
            'current_password' => 'Current123',
            'password' => 'NewSecure456',
            'password_confirmation' => 'NewSecure456',
        ])->assertOk()->assertJsonPath('data.updated', true);

        $this->assertTrue(Hash::check('NewSecure456', (string) $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'profile.password_updated']);

    }

    public function test_user_can_upload_read_and_remove_private_avatar(): void
    {
        Storage::fake('local');
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'test']);
        $user = User::factory()->create(['role' => 'teacher', 'district_id' => $district->id]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('profile.jpg', 480, 480),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatarUrl', fn (string $value): bool => str_starts_with($value, '/api/v1/settings/profile/avatar?v='));

        $path = User::query()->findOrFail($user->id)->avatar_path;
        Storage::disk('local')->assertExists($path);
        $this->get((string) $response->json('data.avatarUrl'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'profile.avatar_updated']);

        $this->deleteJson('/api/v1/settings/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatarUrl', null);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'profile.avatar_removed']);
    }

    public function test_avatar_rejects_non_image_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('document.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_super_admin_branding_is_scoped_to_selected_district(): void
    {
        $selected = District::create(['name' => 'อำเภอหนึ่ง', 'code' => 'one']);
        $other = District::create(['name' => 'อำเภอสอง', 'code' => 'two']);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->withHeader('X-District-Id', (string) $selected->id)
            ->patchJson('/api/v1/super-admin/branding', [
                'schoolName' => 'ศูนย์การเรียนรู้หนึ่ง',
                'portalName' => 'Campus One',
                'welcomeMessage' => 'ยินดีต้อนรับ',
                'primaryColor' => '#126e55',
                'districtName' => 'ห้ามเปลี่ยนชื่ออำเภอจาก client',
            ])->assertOk()
            ->assertJsonPath('data.portalName', 'Campus One')
            ->assertJsonPath('data.districtName', 'อำเภอหนึ่ง');

        $this->assertDatabaseHas('districts', ['id' => $selected->id, 'portal_name' => 'Campus One']);
        $this->assertDatabaseHas('districts', ['id' => $other->id, 'portal_name' => null]);
    }

    public function test_super_admin_can_upload_replace_read_and_remove_district_login_hero(): void
    {
        Storage::fake('local');
        $selected = District::create(['name' => 'อำเภอหนึ่ง', 'code' => 'hero-one']);
        $other = District::create(['name' => 'อำเภอสอง', 'code' => 'hero-two']);
        $user = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($user);

        $firstResponse = $this->withHeader('X-District-Id', (string) $selected->id)
            ->post('/api/v1/super-admin/branding/hero', [
                'hero' => UploadedFile::fake()->image('welcome.jpg', 1600, 1000),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.hasCustomHero', true)
            ->assertJsonPath('data.heroImageUrl', fn (string $value): bool => str_contains($value, "district_id={$selected->id}"));

        $firstPath = (string) $selected->fresh()->login_hero_path;
        $this->assertStringStartsWith("branding/districts/{$selected->id}/hero/", $firstPath);
        Storage::disk('local')->assertExists($firstPath);
        $this->get((string) $firstResponse->json('data.heroImageUrl'))->assertOk();

        $this->withHeader('X-District-Id', (string) $selected->id)
            ->post('/api/v1/super-admin/branding/hero', [
                'hero' => UploadedFile::fake()->image('replacement.png', 1920, 1080),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $secondPath = (string) $selected->fresh()->login_hero_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
        $this->assertNull($other->fresh()->login_hero_path);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'district_id' => $selected->id, 'event' => 'branding.hero_updated']);

        $this->withHeader('X-District-Id', (string) $selected->id)
            ->deleteJson('/api/v1/super-admin/branding/hero')
            ->assertOk()
            ->assertJsonPath('data.hasCustomHero', false)
            ->assertJsonPath('data.heroImageUrl', '/images/sena-students-hero.png');

        Storage::disk('local')->assertMissing($secondPath);
        $this->assertNull($selected->fresh()->login_hero_path);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'district_id' => $selected->id, 'event' => 'branding.hero_removed']);
    }

    public function test_branding_hero_rejects_invalid_file_and_insufficient_dimensions(): void
    {
        Storage::fake('local');
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'hero-validation']);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->withHeader('X-District-Id', (string) $district->id)
            ->post('/api/v1/super-admin/branding/hero', [
                'hero' => UploadedFile::fake()->create('document.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hero');

        $this->withHeader('X-District-Id', (string) $district->id)
            ->post('/api/v1/super-admin/branding/hero', [
                'hero' => UploadedFile::fake()->image('too-small.jpg', 640, 480),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hero');

        $this->assertNull($district->fresh()->login_hero_path);
    }

    public function test_super_admin_can_manage_logo_and_dashboard_hero_assets(): void
    {
        Storage::fake('local');
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'brand-assets']);
        $user = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($user);

        $logoResponse = $this->withHeader('X-District-Id', (string) $district->id)
            ->post('/api/v1/super-admin/branding/assets/logo', [
                'asset' => UploadedFile::fake()->image('logo.png', 512, 512),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.hasCustomLogo', true);

        $dashboardResponse = $this->withHeader('X-District-Id', (string) $district->id)
            ->post('/api/v1/super-admin/branding/assets/dashboard-hero', [
                'asset' => UploadedFile::fake()->image('dashboard.jpg', 1800, 900),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.hasCustomDashboardHero', true);

        $district->refresh();
        Storage::disk('local')->assertExists($district->logo_path);
        Storage::disk('local')->assertExists($district->dashboard_hero_path);
        $this->get((string) $logoResponse->json('data.logoImageUrl'))->assertOk();
        $this->get((string) $dashboardResponse->json('data.dashboardHeroImageUrl'))->assertOk();

        $this->withHeader('X-District-Id', (string) $district->id)
            ->deleteJson('/api/v1/super-admin/branding/assets/logo')
            ->assertOk()
            ->assertJsonPath('data.hasCustomLogo', false);

        $this->withHeader('X-District-Id', (string) $district->id)
            ->deleteJson('/api/v1/super-admin/branding/assets/dashboard-hero')
            ->assertOk()
            ->assertJsonPath('data.hasCustomDashboardHero', false);

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'branding.asset_updated']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'branding.asset_removed']);
    }

    public function test_district_admin_can_upload_only_their_own_logo(): void
    {
        Storage::fake('local');
        $ownDistrict = District::create(['name' => 'อำเภอของผู้ดูแล', 'code' => 'admin-brand']);
        $otherDistrict = District::create(['name' => 'อำเภออื่น', 'code' => 'other-brand']);
        $user = User::factory()->create(['role' => 'admin', 'district_id' => $ownDistrict->id]);
        Sanctum::actingAs($user);

        $this->post('/api/v1/admin/branding/assets/logo', [
            'asset' => UploadedFile::fake()->image('logo.png', 512, 512),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.hasCustomLogo', true);

        $ownDistrict->refresh();
        Storage::disk('local')->assertExists($ownDistrict->logo_path);
        $this->assertNull($otherDistrict->fresh()->logo_path);

        $this->withHeader('X-District-Id', (string) $otherDistrict->id)
            ->post('/api/v1/admin/branding/assets/logo', [
                'asset' => UploadedFile::fake()->image('other.png', 512, 512),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertNull($otherDistrict->fresh()->logo_path);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'district_id' => $ownDistrict->id,
            'event' => 'branding.asset_updated',
        ]);
    }

    public function test_regular_admin_cannot_change_login_hero(): void
    {
        Storage::fake('local');
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'hero-access']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));

        $this->withHeader('X-District-Id', (string) $district->id)
            ->post('/api/v1/super-admin/branding/hero', [
                'hero' => UploadedFile::fake()->image('welcome.jpg', 1600, 1000),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertNull($district->fresh()->login_hero_path);
    }

    public function test_disabled_session_is_rejected_by_production_api(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
