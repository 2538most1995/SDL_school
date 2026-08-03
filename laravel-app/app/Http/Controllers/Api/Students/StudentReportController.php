<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Services\LegacyStudentReportService;
use App\Domain\Students\Services\StudentReportService;
use App\Http\Resources\Students\StudentReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudentReportController extends StudentsApiController
{
    public function __construct(
        private readonly StudentReportService $reports,
        private readonly LegacyStudentReportService $legacyReports,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->reportResponse($request, $this->reports->overview($request->user(), $this->filters($request)));
    }

    public function gradesAboveTwo(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->gradesAboveTwo($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->gradesAboveTwo($request->user(), $filters);

        return $this->reportResponse($request, $data);
    }

    public function newStudents(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->newStudents($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->newStudents($request->user(), $filters);

        return $this->reportResponse($request, $data, false);
    }

    public function graduates(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->graduates($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->graduates($request->user(), $filters);

        return $this->reportResponse($request, $data, false);
    }

    public function expectedGraduates(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->expectedGraduates($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->expectedGraduates($request->user(), $filters);

        return $this->reportResponse($request, $data, false);
    }

    public function transfers(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->transfers($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->transfers($request->user(), $filters);

        return $this->reportResponse($request, $data, false);
    }

    public function registeredSubjects(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->registeredSubjects($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->registeredSubjects($request->user(), $filters);

        return $this->reportResponse($request, $data, false);
    }

    public function examAttendance(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = config('legacy.student_enabled')
            ? $this->legacyReports->examAttendance($request->user(), (int) $request->attributes->get('district_id'), $filters)
            : $this->reports->examAttendance($request->user(), $filters);

        return $this->reportResponse($request, $data);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'district_id' => ['nullable', 'integer', 'min:1'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:80'],
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:30'],
            'view' => ['nullable', Rule::in(['subject', 'student'])],
            'exam_status' => ['nullable', 'string', Rule::in(['taken', 'not_taken'])],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function reportResponse(Request $request, array $data, bool $resource = true): JsonResponse
    {
        return response()->json([
            'data' => $resource ? (new StudentReportResource($data))->resolve($request) : $data,
            'meta' => $this->meta(['filters' => $request->query()]),
        ]);
    }
}
