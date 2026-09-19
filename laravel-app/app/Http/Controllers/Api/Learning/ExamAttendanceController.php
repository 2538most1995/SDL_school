<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\ExamAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExamAttendanceController extends Controller
{
    public function workspace(Request $request, ExamAttendanceService $attendance): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'view' => ['nullable', Rule::in(['subject', 'student'])],
            'subject_code' => ['nullable', 'string', 'max:32'],
            'student_code' => ['nullable', 'string', 'max:64'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()
            ->json(['data' => $attendance->workspace($request->user(), $this->districtId($request), $filters)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request, ExamAttendanceService $attendance): JsonResponse
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $values = $request->validate([
            'term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.student_code' => ['required', 'string', 'max:64'],
            'records.*.subject_code' => ['required', 'string', 'max:32'],
            'records.*.level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'records.*.attended' => ['required', 'boolean'],
        ]);

        return response()->json(['data' => $attendance->save($request->user(), $this->districtId($request), $values, $request->ip())]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }
}
