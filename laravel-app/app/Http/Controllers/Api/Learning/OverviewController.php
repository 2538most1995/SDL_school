<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OverviewController extends Controller
{
    public function __invoke(Request $request, LegacyPortalReadService $legacy): JsonResponse
    {
        abort_unless(config('legacy.enabled'), 404);

        return response()->json([
            'data' => $legacy->overview($request->user(), (int) $request->attributes->get('district_id')),
            'meta' => ['mode' => 'production', 'source' => 'legacy_read_only', 'read_only' => true],
        ]);
    }
}
