<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Services\StudentAcademicService;
use App\Http\Resources\Students\RegisteredSubjectResource;
use App\Http\Resources\Students\StudentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentSubjectsController extends StudentsApiController
{
    public function __construct(private readonly StudentAcademicService $academics) {}

    public function __invoke(Request $request, string $student): JsonResponse
    {
        $filters = $request->validate(['term' => ['nullable', 'regex:/^[12]\/25\d{2}$/']]);
        $result = $this->academics->subjects($request->user(), $student, $filters['term'] ?? null);
        abort_if($result === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return response()->json([
            'data' => [
                'student' => (new StudentSummaryResource($result['student']))->resolve($request),
                'items' => array_map(static fn ($subject): array => (new RegisteredSubjectResource($subject))->resolve($request), $result['items']),
                'summary' => $result['summary'],
            ],
            'meta' => $this->meta(['term' => $filters['term'] ?? 'all']),
        ]);
    }
}
