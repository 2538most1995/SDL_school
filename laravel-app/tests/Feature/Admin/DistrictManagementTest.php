<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessLegacyZipImport;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DistrictManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('system_data.enabled', true);
        config()->set('system_data.write_enabled', true);
    }

    public function test_super_admin_can_register_a_new_district_for_import(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/super-admin/districts', [
            'name' => ' อำเภอบางปะอิน ',
            'code' => ' BANG-PA-IN ',
            'school_code' => '1214120000',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'อำเภอบางปะอิน')
            ->assertJsonPath('data.code', 'bang-pa-in')
            ->assertJsonPath('data.school_code', '1214120000')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.users_count', 0);

        $districtId = (int) $response->json('data.id');
        $this->assertDatabaseHas('districts', [
            'id' => $districtId,
            'name' => 'อำเภอบางปะอิน',
            'code' => 'bang-pa-in',
            'school_code' => '1214120000',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'district_id' => $districtId,
            'event' => 'super_admin.district.created',
            'auditable_type' => 'system_district',
        ]);
        User::factory()->create(['role' => 'admin', 'district_id' => $districtId]);

        $this->getJson('/api/v1/super-admin/districts')
            ->assertOk()
            ->assertJsonFragment(['id' => $districtId, 'code' => 'bang-pa-in', 'users_count' => 1]);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonFragment(['id' => $districtId, 'code' => 'bang-pa-in']);
    }

    public function test_super_admin_can_rename_a_district_without_changing_its_system_code(): void
    {
        $district = District::create([
            'name' => 'อำเภอชื่อเดิม',
            'code' => 'stable-system-code',
            'school_code' => null,
        ]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/super-admin/districts/{$district->id}", [
            'name' => ' อำเภอชื่อใหม่ ',
            'school_code' => '1214120000',
            'code' => 'attempted-change',
        ])->assertOk()
            ->assertJsonPath('data.name', 'อำเภอชื่อใหม่')
            ->assertJsonPath('data.code', 'stable-system-code')
            ->assertJsonPath('data.school_code', '1214120000');

        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'name' => 'อำเภอชื่อใหม่',
            'code' => 'stable-system-code',
            'school_code' => '1214120000',
        ]);
        $audit = (array) \DB::table('audit_logs')
            ->where('event', 'super_admin.district.updated')
            ->where('auditable_id', $district->id)
            ->first();
        $this->assertSame('อำเภอชื่อเดิม', json_decode((string) $audit['before'], true, flags: JSON_THROW_ON_ERROR)['name']);
        $this->assertSame('อำเภอชื่อใหม่', json_decode((string) $audit['after'], true, flags: JSON_THROW_ON_ERROR)['name']);
    }

    public function test_name_only_patch_preserves_the_existing_school_code(): void
    {
        $district = District::create([
            'name' => 'อำเภอชื่อเดิม',
            'code' => 'stable-code',
            'school_code' => '1214120000',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->patchJson("/api/v1/super-admin/districts/{$district->id}", [
            'name' => 'อำเภอชื่อใหม่',
        ])->assertOk()
            ->assertJsonPath('data.name', 'อำเภอชื่อใหม่')
            ->assertJsonPath('data.school_code', '1214120000');

        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'school_code' => '1214120000',
        ]);
    }

    public function test_district_admin_cannot_register_or_list_other_districts(): void
    {
        $district = District::create(['name' => 'อำเภอเดิม', 'code' => 'existing']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));

        $this->getJson('/api/v1/super-admin/districts')->assertForbidden();
        $this->postJson('/api/v1/super-admin/districts', [
            'name' => 'อำเภอที่ห้ามสร้าง',
            'code' => 'forbidden',
        ])->assertForbidden();
        $this->patchJson("/api/v1/super-admin/districts/{$district->id}", [
            'name' => 'อำเภอที่ห้ามแก้',
            'school_code' => '1214120000',
        ])->assertForbidden();
        $this->assertDatabaseMissing('districts', ['code' => 'forbidden']);
    }

    public function test_district_code_must_be_unique_and_writes_must_be_enabled(): void
    {
        District::create(['name' => 'อำเภอเดิม', 'code' => 'existing']);
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'district_id' => null]));

        $this->postJson('/api/v1/super-admin/districts', [
            'name' => 'อำเภอซ้ำ',
            'code' => 'EXISTING',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/super-admin/districts/1', [
            'name' => 'อำเภอเดิม',
            'school_code' => '12A4120000',
        ])->assertUnprocessable()->assertJsonValidationErrors('school_code');
        $this->patchJson('/api/v1/super-admin/districts/1', [
            'name' => '   ',
            'school_code' => '1214120000',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        config()->set('system_data.write_enabled', false);
        $this->postJson('/api/v1/super-admin/districts', [
            'name' => 'อำเภอปิดเขียน',
            'code' => 'disabled',
        ])->assertServiceUnavailable();
        $this->patchJson('/api/v1/super-admin/districts/1', [
            'name' => 'อำเภอปิดเขียน',
            'school_code' => '1214120000',
        ])->assertServiceUnavailable();
    }

    public function test_super_admin_import_is_dispatched_to_the_newly_selected_district_only(): void
    {
        Storage::fake('local');
        Bus::fake();
        $existing = District::create(['name' => 'อำเภอเดิม', 'code' => 'existing']);
        $newDistrict = District::create(['name' => 'อำเภอใหม่', 'code' => 'new-district']);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'district_id' => null]);
        Sanctum::actingAs($superAdmin);

        $response = $this->withHeader('X-District-Id', (string) $newDistrict->id)
            ->post('/api/v1/admin/imports', [
                'archive' => UploadedFile::fake()->create('itw51.zip', 100, 'application/zip'),
                'academic_term' => '1/2569',
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.district_id', $newDistrict->id);

        $jobId = (string) $response->json('data.job_id');
        Storage::disk('local')->assertExists("import-queue/{$newDistrict->id}/{$jobId}.zip");
        Storage::disk('local')->assertMissing("import-queue/{$existing->id}/{$jobId}.zip");
        Bus::assertDispatched(ProcessLegacyZipImport::class, fn (ProcessLegacyZipImport $job): bool => $job->districtId === $newDistrict->id
            && $job->userId === $superAdmin->id);
    }
}
