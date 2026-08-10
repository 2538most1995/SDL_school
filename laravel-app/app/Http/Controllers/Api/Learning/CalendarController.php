<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CalendarController extends Controller
{
    public function __invoke(Request $request, DemoLearningPortal $portal, LegacyPortalReadService $legacy): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(['assignment', 'meeting', 'exam', 'activity'])],
        ]);

        $items = config('system_data.enabled')
            ? $legacy->calendar($request->user(), (int) $request->attributes->get('district_id'), $filters['type'] ?? null)
            : $portal->calendar($filters['type'] ?? null);

        return response()->json([
            'data' => $items,
            'meta' => config('system_data.enabled') ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled'), 'pagination' => ['page' => 1, 'per_page' => count($items), 'total' => count($items), 'last_page' => 1], 'filters' => $filters] : DemoResponseMeta::collection(count($items), $filters),
        ]);
    }

    public function image(Request $request, int $event): StreamedResponse
    {
        $viewer = $request->user();
        $districtId = $viewer->role === 'super_admin'
            ? filter_var($request->query('district_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : (int) $viewer->district_id;
        abort_unless($districtId, 422, 'รหัสอำเภอไม่ถูกต้อง');
        abort_unless(DB::table('districts')->where('id', $districtId)->where('is_active', true)->exists(), 404);
        $query = DB::table('learning_calendar_events')
            ->where('id', $event)
            ->where('district_id', $districtId);

        if ($viewer->role === 'student') {
            $groups = array_values(array_filter(array_map('strval', $viewer->assigned_groups ?? [])));
            $query->where(function ($scope) use ($groups): void {
                $scope->where('target_type', 'all');
                if ($groups !== []) {
                    $scope->orWhere(fn ($groupScope) => $groupScope
                        ->where('target_type', 'group')
                        ->whereIn('target_value', $groups));
                }
            });
        } elseif ($viewer->role === 'teacher') {
            $groups = array_values(array_filter(array_map('strval', $viewer->assigned_groups ?? [])));
            $query->where(function ($scope) use ($groups, $viewer): void {
                $scope->where('created_by', (int) $viewer->id);
                if ($groups !== []) {
                    $scope->orWhere(fn ($groupScope) => $groupScope
                        ->where('target_type', 'group')
                        ->whereIn('target_value', $groups));
                }
            });
        }

        $row = $query->first(['id', 'district_id', 'image_path']);
        abort_unless($row, 404);
        $path = (string) ($row->image_path ?? '');
        abort_unless(
            $path !== ''
            && str_starts_with($path, "learning/calendar/{$districtId}/{$event}/")
            && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
