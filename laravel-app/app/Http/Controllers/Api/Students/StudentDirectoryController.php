<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Services\StudentAcademicService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Resources\Students\StudentDetailResource;
use App\Http\Resources\Students\StudentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudentDirectoryController extends StudentsApiController
{
    public function __construct(
        private readonly StudentDirectoryService $directory,
        private readonly StudentAcademicService $academics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'kpch_status' => ['nullable', Rule::in(['complete', 'incomplete'])],
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'include_term_gpa' => ['sometimes', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'gpax', 'credits', 'kpch_hours'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $result = $this->directory->paginate($request->user(), $filters, selectAcademicTerm: true);
        $includeTermGpa = $request->boolean('include_term_gpa');
        $term = $result['meta']['selected_term'];
        $termGpas = $includeTermGpa && $term !== null ? $this->academics->termGpas($result['items'], $term) : [];

        return response()->json([
            'data' => array_map(
                static function (Student $student) use ($request, $includeTermGpa, $termGpas): array {
                    $data = (new StudentSummaryResource($student))->resolve($request);
                    if ($includeTermGpa) {
                        $data['academic']['term_gpa'] = $termGpas["{$student->districtId}|{$student->level}|{$student->code}"] ?? null;
                    }

                    return $data;
                },
                $result['items'],
            ),
            'meta' => $this->meta($result['meta']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $student): JsonResponse
    {
        $filters = $request->validate(['term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'], 'level' => ['nullable', 'integer', Rule::in([1, 2, 3])]]);
        $record = $this->directory->findForDirectory($request->user(), $student, $filters['level'] ?? null, $filters['term'] ?? null);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return response()->json([
            'data' => (new StudentDetailResource($record, $this->directory->profileDetails($request->user(), $record)))->resolve($request),
            'meta' => $this->meta(['selected_term' => $filters['term'] ?? null, 'snapshot_note' => 'ผลการเรียนและ กพช. สะสมถึงภาคเรียนที่เลือก ข้อมูลส่วนตัวและกลุ่มเรียนจากชุด ITW ล่าสุด']),
        ])->header('Cache-Control', 'private, no-store');
    }
}
