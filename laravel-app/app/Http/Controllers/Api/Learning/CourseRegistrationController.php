<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Students\Services\CourseRegistrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseRegistrationController extends Controller
{
    public function __construct(
        private readonly CourseRegistrationService $service,
    ) {}

    public function workspace(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'string', 'max:16'],
            'group' => ['nullable', 'string', 'max:64'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $data = $this->service->workspace($request->user(), $filters);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function studentRegistration(Request $request, string $student): JsonResponse
    {
        $term = $request->query('term');
        $data = $this->service->studentRegistration($request->user(), $student, $term ? (string) $term : null);

        abort_if($data === null, 404, 'ไม่พบข้อมูลนักศึกษาหรือไม่มีสิทธิ์เข้าถึง');

        return response()->json([
            'data' => $data,
        ]);
    }

    public function saveRegistration(Request $request, string $student): JsonResponse
    {
        $validated = $request->validate([
            'academic_term' => ['required', 'string', 'max:16'],
            'compulsory_subjects' => ['present', 'array'],
            'compulsory_subjects.*.code' => ['required', 'string', 'max:32'],
            'compulsory_subjects.*.name' => ['nullable', 'string', 'max:120'],
            'compulsory_subjects.*.credits' => ['nullable', 'numeric'],
            'compulsory_subjects.*.registered' => ['nullable', 'boolean'],
            'compulsory_subjects.*.transferred' => ['nullable', 'boolean'],
            'compulsory_subjects.*.remark' => ['nullable', 'string', 'max:120'],
            'elective_subjects' => ['present', 'array'],
            'elective_subjects.*.code' => ['nullable', 'string', 'max:32'],
            'elective_subjects.*.name' => ['nullable', 'string', 'max:120'],
            'elective_subjects.*.credits' => ['nullable', 'numeric'],
            'elective_subjects.*.registered' => ['nullable', 'boolean'],
            'elective_subjects.*.transferred' => ['nullable', 'boolean'],
            'elective_subjects.*.remark' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = $this->service->save($request->user(), $student, $validated);

        return response()->json([
            'data' => $saved,
            'message' => 'บันทึกการลงทะเบียนเรียนสำเร็จ',
        ]);
    }
}
