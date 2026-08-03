<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CalendarController extends Controller
{
    public function __invoke(Request $request, DemoLearningPortal $portal, LegacyPortalReadService $legacy): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['assignment', 'meeting', 'exam', 'activity'])],
        ]);

        $items = config('legacy.enabled')
            ? $legacy->calendar($request->user(), (int) $request->attributes->get('district_id'), $filters['type'] ?? null)
            : $portal->calendar($filters['type'] ?? null);

        return response()->json([
            'data' => $items,
            'meta' => config('legacy.enabled') ? ['mode' => 'production', 'source' => 'legacy_controlled_write', 'read_only' => ! (bool) config('legacy.write_enabled'), 'pagination' => ['page' => 1, 'per_page' => count($items), 'total' => count($items), 'last_page' => 1], 'filters' => $filters] : DemoResponseMeta::collection(count($items), $filters),
        ]);
    }
}
