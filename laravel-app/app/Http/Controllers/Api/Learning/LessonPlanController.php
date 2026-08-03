<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LessonPlanController extends Controller
{
    public function __invoke(Request $request, LegacyPortalReadService $legacy): JsonResponse
    {
        abort_unless(config('legacy.enabled'), 404);
        $items = $legacy->lessonPlans($request->user(), (int) $request->attributes->get('district_id'));

        return response()->json(['data' => $items, 'meta' => ['mode' => 'production', 'source' => 'legacy_controlled_write', 'read_only' => ! (bool) config('legacy.write_enabled')]]);
    }
}
