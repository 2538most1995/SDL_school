<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Http\Controllers\Controller;
use App\Services\Learning\LearningScorebookService;
use App\Services\Legacy\LegacyPortalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ScoreController extends Controller
{
    public function __invoke(
        Request $request,
        DemoLearningPortal $portal,
        LegacyPortalReadService $legacy,
        LearningScorebookService $scorebooks,
    ): JsonResponse {
        $systemEnabled = (bool) config('system_data.enabled');
        $data = $systemEnabled
            ? $legacy->scores($request->user(), (int) $request->attributes->get('district_id'))
            : $portal->scores();
        if ($request->user()?->role === 'student') {
            $scorebookData = $scorebooks->studentScores($request->user(), (int) $request->attributes->get('district_id'));
            if ($scorebookData['courses'] !== []) {
                $data = $scorebookData;
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => $systemEnabled ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => true] : DemoResponseMeta::item(),
        ]);
    }

    public function workspace(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'subject_code' => ['nullable', 'string', 'max:32'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'scorebook_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $scorebooks->workspace($request->user(), $this->districtId($request), $filters),
            'meta' => ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled')],
        ]);
    }

    public function store(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
            'subject_code' => ['required', 'string', 'max:32'],
            'level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.title' => ['required', 'string', 'max:120'],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->create($request->user(), $this->districtId($request), $values, $request->ip()),
        ], 201);
    }

    public function structure(Request $request, int $scorebook, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'components.*.title' => ['required', 'string', 'max:120'],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->updateStructure(
                $request->user(),
                $this->districtId($request),
                $scorebook,
                $values['components'],
                $request->ip(),
            ),
        ]);
    }

    public function entries(Request $request, int $scorebook, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'students' => ['required', 'array', 'max:1000'],
            'students.*.student_code' => ['required', 'string', 'max:64', 'distinct'],
            'students.*.note' => ['nullable', 'string', 'max:2000'],
            'students.*.scores' => ['required', 'array', 'max:20'],
            'students.*.scores.*.component_id' => ['required', 'integer', 'min:1'],
            'students.*.scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->saveEntries(
                $request->user(),
                $this->districtId($request),
                $scorebook,
                $values['students'],
                $request->ip(),
            ),
        ]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function assertWriteEnabled(): void
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }
}
