<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_export_endpoint_records_audit_log(): void
    {
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'test-audit', 'is_active' => true]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'district_id' => $district->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->postJson('/api/v1/reports/audit-export', [
                'report_name' => 'รายงานรายชื่อนักศึกษา_2568.xlsx',
                'row_count' => 150,
                'sheets' => [['name' => 'ข้อมูล', 'rows' => 150]],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'district_id' => $district->id,
            'event' => 'reports.data_exported',
            'auditable_type' => 'export',
            'ip_address' => '192.168.1.100',
        ]);
    }

    public function test_nnet_clear_records_audit_log(): void
    {
        $district = District::create(['name' => 'อำเภอทดสอบ', 'code' => 'test-nnet', 'is_active' => true]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'district_id' => $district->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.42'])
            ->postJson('/api/v1/nnet/clear', [
                'academic_year' => '2567',
                'round' => '1',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'district_id' => $district->id,
            'event' => 'nnet.records_cleared',
            'auditable_type' => 'nnet_result',
            'ip_address' => '10.0.0.42',
        ]);
    }
}
