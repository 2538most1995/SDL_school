<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\LessonPlanDocumentService;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LessonPlanController extends Controller
{
    public function __invoke(Request $request, LegacyPortalReadService $legacy): JsonResponse
    {
        $filters = $request->validate([
            'plan_scope' => ['nullable', Rule::in(['subject', 'all_subjects'])],
            'education_level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
        ]);
        $items = $legacy->lessonPlans($request->user(), (int) $request->attributes->get('district_id'), $filters);

        return response()->json(['data' => $items, 'meta' => ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled')]]);
    }

    public function store(Request $request, LessonPlanDocumentService $documents): JsonResponse
    {
        $id = $documents->save($request);

        return response()->json(['data' => ['id' => (string) $id]], 201);
    }

    public function update(Request $request, int $plan, LessonPlanDocumentService $documents): JsonResponse
    {
        $documents->save($request, $plan);

        return response()->json(['data' => ['id' => (string) $plan]]);
    }

    public function destroy(Request $request, int $plan, LessonPlanDocumentService $documents): JsonResponse
    {
        $documents->destroy($request, $plan);

        return response()->json(['data' => ['id' => (string) $plan, 'deleted' => true]]);
    }

    public function file(Request $request, int $plan, LessonPlanDocumentService $documents): BinaryFileResponse
    {
        return $documents->file($request, $plan);
    }

    public function word(Request $request, int $plan, LessonPlanDocumentService $documents): BinaryFileResponse
    {
        return $documents->word($request, $plan);
    }

    public function cover(Request $request, int $plan, LessonPlanDocumentService $documents): BinaryFileResponse
    {
        return $documents->file($request, $plan, true);
    }
}
