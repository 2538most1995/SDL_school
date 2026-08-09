<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\Admin\ExamRoomController;
use App\Http\Controllers\Api\Admin\ImportController;
use App\Http\Controllers\Api\Admin\ImportSafetyController;
use App\Http\Controllers\Api\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminDemoApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_contract/admin/users', UserController::class);
        Route::get('/api/_contract/admin/imports', ImportController::class);
        Route::get('/api/_contract/admin/imports/safety', ImportSafetyController::class);
        Route::get('/api/_contract/admin/exam-rooms', ExamRoomController::class);
    }

    public function test_demo_user_directory_is_filterable_and_has_no_sensitive_identity_fields(): void
    {
        $response = $this->getJson('/api/_contract/admin/users?role=teacher');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'demo-user-003')
            ->assertJsonPath('data.0.role', 'teacher')
            ->assertJsonPath('meta.contains_personal_data', false)
            ->assertJsonPath('meta.read_only', true);

        $payload = $response->getContent();

        $this->assertStringNotContainsString('citizen_id', $payload);
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('email', $payload);
    }

    public function test_import_preview_is_disconnected_from_real_data_and_write_operations(): void
    {
        $this->getJson('/api/_contract/admin/imports?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'demo-import-002')
            ->assertJsonPath('meta.legacy_database_connected', false)
            ->assertJsonPath('meta.write_operations_enabled', false)
            ->assertJsonPath('meta.read_only', true);

        $this->getJson('/api/_contract/admin/imports/safety')
            ->assertOk()
            ->assertJsonPath('data.mode', 'demo')
            ->assertJsonPath('data.overall_state', 'locked')
            ->assertJsonPath('data.legacy_database.connected', false)
            ->assertJsonPath('data.legacy_database.writes_enabled', false)
            ->assertJsonPath('data.operations.0.state', 'disabled')
            ->assertJsonPath('data.operations.2.key', 'activate')
            ->assertJsonPath('data.operations.2.state', 'disabled')
            ->assertJsonPath('data.operations.3.key', 'delete')
            ->assertJsonPath('data.operations.3.state', 'disabled')
            ->assertJsonPath('data.guarantees.active_batch_will_not_change', true)
            ->assertJsonPath('data.guarantees.real_tables_will_not_be_created_or_dropped', true);
    }

    public function test_exam_rooms_are_demo_only_and_date_filter_is_validated(): void
    {
        $this->getJson('/api/_contract/admin/exam-rooms?date=2026-07-28')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 3)
            ->assertJsonPath('data.0.term', '2/2568')
            ->assertJsonPath('data.0.subject_code', 'คณ21001')
            ->assertJsonPath('data.0.assignment_type', 'student_range')
            ->assertJsonPath('data.0.start_val', '6650100031')
            ->assertJsonPath('data.0.end_val', '6650100054')
            ->assertJsonPath('data.0.room_name', 'ห้องประชุมใหญ่')
            ->assertJsonPath('data.0.capacity', 24)
            ->assertJsonPath('meta.source_batch', 'demo-only')
            ->assertJsonPath('meta.sync_enabled', false);

        $this->getJson('/api/_contract/admin/exam-rooms?date=17-07-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_invalid_admin_filters_are_rejected(): void
    {
        $this->getJson('/api/_contract/admin/users?role=owner')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->getJson('/api/_contract/admin/imports?status=deleted')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
