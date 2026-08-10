<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScoreController extends Controller
{
    public function __invoke(Request $request, DemoLearningPortal $portal, LegacyPortalReadService $legacy): JsonResponse
    {
        return response()->json([
            'data' => config('system_data.enabled') ? $legacy->scores($request->user(), (int) $request->attributes->get('district_id')) : $portal->scores(),
            'meta' => config('system_data.enabled') ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => true] : DemoResponseMeta::item(),
        ]);
    }
}
