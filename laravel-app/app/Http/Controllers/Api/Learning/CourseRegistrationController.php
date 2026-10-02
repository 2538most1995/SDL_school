<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Students\Services\CourseRegistrationService;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
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
            'graduation_status' => ['nullable', 'string', 'in:complete,potential'],
        ]);

        $data = $this->service->workspace(
            $request->user(),
            (int) $request->attributes->get('district_id'),
            $filters,
        );

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
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');

        $validated = $request->validate([
            'academic_term' => ['required', 'string', 'max:16'],
            'compulsory_subjects' => ['present', 'array'],
            'compulsory_subjects.*.code' => ['required', 'string', 'max:32', 'distinct'],
            'compulsory_subjects.*.name' => ['nullable', 'string', 'max:120'],
            'compulsory_subjects.*.credits' => ['nullable', 'numeric', 'between:0,10'],
            'compulsory_subjects.*.registered' => ['nullable', 'boolean'],
            'compulsory_subjects.*.transferred' => ['nullable', 'boolean'],
            'compulsory_subjects.*.remark' => ['nullable', 'string', 'max:120'],
            'elective_subjects' => ['present', 'array'],
            'elective_subjects.*.code' => ['nullable', 'string', 'max:32'],
            'elective_subjects.*.name' => ['nullable', 'string', 'max:120'],
            'elective_subjects.*.credits' => ['nullable', 'numeric', 'between:0,10'],
            'elective_subjects.*.registered' => ['nullable', 'boolean'],
            'elective_subjects.*.transferred' => ['nullable', 'boolean'],
            'elective_subjects.*.remark' => ['nullable', 'string', 'max:120'],
            'student_info' => ['nullable', 'array'],
            'student_info.name' => ['nullable', 'string', 'max:120'],
            'student_info.citizen_id' => ['nullable', 'string', 'max:32'],
            'student_info.code' => ['nullable', 'string', 'max:32'],
            'student_info.phone' => ['nullable', 'string', 'max:32'],
            'student_info.facebook' => ['nullable', 'string', 'max:120'],
            'student_info.line_id' => ['nullable', 'string', 'max:64'],
            'student_info.house_no' => ['nullable', 'string', 'max:32'],
            'student_info.moo' => ['nullable', 'string', 'max:32'],
            'student_info.subdistrict' => ['nullable', 'string', 'max:64'],
            'student_info.district' => ['nullable', 'string', 'max:64'],
            'student_info.province' => ['nullable', 'string', 'max:64'],
            'student_info.group' => ['nullable', 'string', 'max:64'],
            'student_info.box_subdistrict' => ['nullable', 'string', 'max:64'],
            'student_info.compulsory_earned' => ['nullable'],
            'student_info.elective_earned' => ['nullable'],
            'student_info.compulsory_remaining' => ['nullable'],
            'student_info.elective_remaining' => ['nullable'],
            'student_info.term_no' => ['nullable', 'string', 'max:16'],
            'student_info.term_year' => ['nullable', 'string', 'max:16'],
            'student_info.teacher_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = $this->service->save($request->user(), $student, $validated);
        AuditService::logFromRequest(
            $request,
            'learning.course_registration.saved',
            'system_learning_course_registration',
            context: [
                'student_code' => $student,
                'academic_term' => $saved['academic_term'] ?? $validated['academic_term'],
                'compulsory_subject_count' => count($validated['compulsory_subjects']),
                'elective_subject_count' => count($validated['elective_subjects']),
                'total_selected_credits' => $saved['credit_policy']['total_selected'] ?? null,
                'is_credit_complete' => $saved['credit_policy']['is_credit_complete'] ?? false,
                'is_potential_graduate' => $saved['credit_policy']['is_potential_graduate'] ?? false,
            ],
        );

        return response()->json([
            'data' => $saved,
            'message' => 'บันทึกการลงทะเบียนเรียนสำเร็จ',
        ]);
    }
}
