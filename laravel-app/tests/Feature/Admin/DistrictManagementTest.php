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
        ])->assertCreated()
            ->assertJsonPath('data.name', 'อำเภอบางปะอิน')
            ->assertJsonPath('data.code', 'bang-pa-in')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.users_count', 0);

        $districtId = (int) $response->json('data.id');
        $this->assertDatabaseHas('districts', [
            'id' => $districtId,
            'name' => 'อำเภอบางปะอิน',
            'code' => 'bang-pa-in',
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

    public function test_district_admin_cannot_register_or_list_other_districts(): void
    {
        $district = District::create(['name' => 'อำเภอเดิม', 'code' => 'existing']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'district_id' => $district->id]));

        $this->getJson('/api/v1/super-admin/districts')->assertForbidden();
        $this->postJson('/api/v1/super-admin/districts', [
            'name' => 'อำเภอที่ห้ามสร้าง',
            'code' => 'forbidden',
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

        config()->set('system_data.write_enabled', false);
        $this->postJson('/api/v1/super-admin/districts', [
            'name' => 'อำเภอปิดเขียน',
            'code' => 'disabled',
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
