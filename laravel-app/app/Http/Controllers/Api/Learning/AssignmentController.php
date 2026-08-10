<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoQueryRules;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssignmentController extends Controller
{
    public function __invoke(Request $request, DemoLearningPortal $portal, LegacyPortalReadService $legacy): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'in_progress', 'completed'])],
            'search' => DemoQueryRules::search(),
        ]);

        $items = config('system_data.enabled')
            ? $legacy->assignments($request->user(), (int) $request->attributes->get('district_id'), $filters['status'] ?? null, $filters['search'] ?? null)
            : $portal->assignments($filters['status'] ?? null, $filters['search'] ?? null);

        return response()->json([
            'data' => $items,
            'meta' => config('system_data.enabled') ? $this->realMeta(count($items), $filters) : DemoResponseMeta::collection(count($items), $filters),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function realMeta(int $total, array $filters): array
    {
        return ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled'), 'pagination' => ['page' => 1, 'per_page' => $total, 'total' => $total, 'last_page' => 1], 'filters' => $filters];
    }
}
