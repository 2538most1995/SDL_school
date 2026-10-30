<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoQueryRules;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Learning\ClassroomUnitContentScope;
use App\Services\Learning\DistrictLearningGroupCatalog;
use App\Services\Learning\OnlineClassroomService;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ResourceController extends Controller
{
    public function __invoke(
        Request $request,
        DemoLearningPortal $portal,
        LegacyPortalReadService $legacy,
        DistrictLearningGroupCatalog $groupCatalog,
        OnlineClassroomService $classroom,
    ): JsonResponse {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::in(['คู่มือ', 'วิดีโอ', 'แบบฝึกหัด', 'พอดแคสต์'])],
            'search' => DemoQueryRules::search(),
            'subject_code' => ['nullable', 'required_with:education_level', 'string', 'max:32'],
            'education_level' => ['nullable', 'required_with:subject_code', 'integer', Rule::in([1, 2, 3])],
            'unit_id' => ClassroomUnitContentScope::rules(),
            'classroom_kind' => ['nullable', Rule::in(['all', 'video', 'knowledge'])],
            'shared_only' => ['sometimes', 'boolean'],
        ]);

        $districtId = (int) $request->attributes->get('district_id');
        $viewer = $request->user();
        $classroomRequest = isset($filters['subject_code']) || isset($filters['classroom_kind']) || isset($filters['unit_id']) || $request->boolean('shared_only');
        if (isset($filters['subject_code'])) {
            if ($request->boolean('shared_only')) {
                throw ValidationException::withMessages(['shared_only' => 'เลือกสื่อรายวิชาหรือสื่อส่วนกลางอย่างใดอย่างหนึ่ง']);
            }
            $subjects = $classroom->catalog($viewer, $districtId)['subjects'];
            abort_unless(collect($subjects)->contains(static fn (array $subject): bool => $subject['code'] === $filters['subject_code']
                && (int) $subject['level'] === (int) $filters['education_level']), 404);
        }
        // Classroom uploads use the deployment database even with demo rosters.
        // The original unfiltered demo API keeps its existing fixture contract.
        $databaseRead = config('system_data.enabled') || $classroomRequest;
        $items = $databaseRead
            ? $legacy->resources($viewer, $districtId, $filters['category'] ?? null, $filters['search'] ?? null, $filters)
            : $portal->resources($filters['category'] ?? null, $filters['search'] ?? null);
        $availableGroups = $viewer === null || $viewer->role === 'student'
            ? []
            : $groupCatalog->groupsForViewer($viewer, $districtId);

        return response()->json([
            'data' => $items,
            'meta' => $databaseRead
                ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled'), 'available_groups' => $availableGroups, 'pagination' => ['page' => 1, 'per_page' => count($items), 'total' => count($items), 'last_page' => 1], 'filters' => $filters]
                : [...DemoResponseMeta::collection(count($items), $filters), 'available_groups' => []],
        ]);
    }
}
