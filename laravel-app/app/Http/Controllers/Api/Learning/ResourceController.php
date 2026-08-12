<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoQueryRules;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Learning\DistrictLearningGroupCatalog;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ResourceController extends Controller
{
    public function __invoke(
        Request $request,
        DemoLearningPortal $portal,
        LegacyPortalReadService $legacy,
        DistrictLearningGroupCatalog $groupCatalog,
    ): JsonResponse {
        $filters = $request->validate([
            'category' => ['nullable', 'string', Rule::in(['คู่มือ', 'วิดีโอ', 'แบบฝึกหัด', 'พอดแคสต์'])],
            'search' => DemoQueryRules::search(),
        ]);

        $items = config('system_data.enabled')
            ? $legacy->resources($request->user(), (int) $request->attributes->get('district_id'), $filters['category'] ?? null, $filters['search'] ?? null)
            : $portal->resources($filters['category'] ?? null, $filters['search'] ?? null);
        $districtId = (int) $request->attributes->get('district_id');
        $viewer = $request->user();
        $availableGroups = $viewer === null || $viewer->role === 'student'
            ? []
            : $groupCatalog->groupsForViewer($viewer, $districtId);

        return response()->json([
            'data' => $items,
            'meta' => config('system_data.enabled')
                ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled'), 'available_groups' => $availableGroups, 'pagination' => ['page' => 1, 'per_page' => count($items), 'total' => count($items), 'last_page' => 1], 'filters' => $filters]
                : [...DemoResponseMeta::collection(count($items), $filters), 'available_groups' => []],
        ]);
    }
}
