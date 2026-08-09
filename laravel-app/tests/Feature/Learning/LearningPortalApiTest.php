<?php

namespace Tests\Feature\Learning;

use App\Http\Controllers\Api\Learning\AssignmentController;
use App\Http\Controllers\Api\Learning\CalendarController;
use App\Http\Controllers\Api\Learning\OverviewController;
use App\Http\Controllers\Api\Learning\ResourceController;
use App\Http\Controllers\Api\Learning\ScoreController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LearningPortalApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_contract/learning/assignments', AssignmentController::class);
        Route::get('/api/_contract/learning', OverviewController::class);
        Route::get('/api/_contract/learning/resources', ResourceController::class);
        Route::get('/api/_contract/learning/calendar', CalendarController::class);
        Route::get('/api/_contract/learning/scores', ScoreController::class);
    }

    public function test_learning_overview_returns_the_demo_contract_when_legacy_is_disabled(): void
    {
        config(['legacy.enabled' => false]);

        $this->getJson('/api/_contract/learning')
            ->assertOk()
            ->assertJsonPath('data.studentName', 'ผู้ใช้งานสาธิต')
            ->assertJsonPath('data.dueAssignments', 3)
            ->assertJsonPath('data.completedAssignments', 1)
            ->assertJsonPath('data.resources', 4)
            ->assertJsonCount(4, 'data.courses')
            ->assertJsonCount(4, 'data.upcoming')
            ->assertJsonPath('meta.mode', 'demo')
            ->assertJsonPath('meta.source', 'canonical_demo')
            ->assertJsonPath('meta.read_only', true);
    }

    public function test_assignments_return_a_filterable_canonical_demo_collection(): void
    {
        $search = rawurlencode('ชุมชน');
        $response = $this->getJson("/api/_contract/learning/assignments?status=pending&search={$search}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'assignment-003')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('meta.mode', 'demo')
            ->assertJsonPath('meta.source', 'canonical_demo')
            ->assertJsonPath('meta.contains_personal_data', false)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_learning_collections_expose_stable_fields_for_tanstack_query_pages(): void
    {
        $this->getJson('/api/_contract/learning/resources')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'title', 'description', 'category', 'type', 'subject_code',
                    'duration_minutes', 'published_at', 'is_downloadable', 'accent',
                ]],
                'meta' => ['mode', 'generated_at', 'pagination'],
            ]);

        $this->getJson('/api/_contract/learning/calendar?type=exam')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'event-003')
            ->assertJsonPath('data.0.location', 'อาคาร 2 ห้อง 204');

        $this->getJson('/api/_contract/learning/scores')
            ->assertOk()
            ->assertJsonPath('data.term', '2/2568')
            ->assertJsonPath('data.summary.grade_point_average', 3.24)
            ->assertJsonCount(4, 'data.courses')
            ->assertJsonPath('meta.read_only', true);
    }

    public function test_invalid_learning_filters_are_rejected(): void
    {
        $this->getJson('/api/_contract/learning/assignments?status=deleted')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/_contract/learning/calendar?type=private')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->getJson('/api/_contract/learning/assignments?search=%A4')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }
}
