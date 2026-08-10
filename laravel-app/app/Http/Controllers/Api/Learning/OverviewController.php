<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OverviewController extends Controller
{
    public function __invoke(Request $request, DemoLearningPortal $portal, LegacyPortalReadService $legacy): JsonResponse
    {
        $production = (bool) config('system_data.enabled');
        $data = $production
            ? $legacy->overview($request->user(), (int) $request->attributes->get('district_id'))
            : $portal->overview($request->user()?->name ?? 'ผู้ใช้งานสาธิต');

        return response()->json([
            'data' => $data,
            'meta' => $production
                ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => true]
                : DemoResponseMeta::item(),
        ]);
    }
}
