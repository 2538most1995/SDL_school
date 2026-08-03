<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScheduleController extends Controller
{
    public function __invoke(Request $request, LegacyPortalReadService $legacy): JsonResponse
    {
        abort_unless(config('legacy.enabled'), 404);
        $items = $legacy->schedules($request->user(), (int) $request->attributes->get('district_id'));

        return response()->json(['data' => $items, 'meta' => ['mode' => 'production', 'source' => 'legacy_read_only', 'read_only' => true]]);
    }
}
