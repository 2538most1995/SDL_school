<?php

namespace App\Http\Controllers\Api;

use App\Domain\Students\Services\NnetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NnetController extends Controller
{
    public function __construct(
        private readonly NnetService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'district_id' => ['nullable', 'integer'],
            'education_level' => ['nullable', 'integer', 'in:1,2,3'],
            'academic_year' => ['nullable', 'string', 'max:8'],
            'round' => ['nullable', 'integer', 'in:1,2'],
            'status' => ['nullable', 'string', 'in:scored,absent'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort_by' => ['nullable', 'string', 'max:32'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:5', 'max:500'],
        ]);

        $data = $this->service->listRecords($filters, $request->user());

        return response()->json([
            'data' => $data,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'district_id' => ['nullable', 'integer'],
            'education_level' => ['nullable', 'integer', 'in:1,2,3'],
            'academic_year' => ['nullable', 'string', 'max:8'],
            'round' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $data = $this->service->summary($filters, $request->user());

        return response()->json([
            'data' => $data,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        // Only teachers and admins can import
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403, 'ไม่มีสิทธิ์นำเข้าข้อมูล N-NET');

        $validated = $request->validate([
            'district_id' => ['nullable', 'integer'],
            'education_level' => ['required', 'integer', 'in:1,2,3'],
            'academic_year' => ['required', 'string', 'max:8'],
            'round' => ['required', 'integer', 'in:1,2'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.citizen' => ['nullable', 'string', 'max:32'],
            'rows.*.citizen_id' => ['nullable', 'string', 'max:32'],
            'rows.*.name' => ['nullable', 'string', 'max:191'],
            'rows.*.seat' => ['nullable', 'string', 'max:32'],
            'rows.*.seat_no' => ['nullable', 'string', 'max:32'],
            'rows.*.total' => ['nullable'],
            'rows.*.total_score' => ['nullable'],
            'rows.*.scores' => ['nullable', 'array'],
            'rows.*.levels' => ['nullable', 'array'],
            'subject_codes' => ['nullable', 'array'],
            'subject_names' => ['nullable', 'array'],
        ]);

        $result = $this->service->bulkImport($validated, $request->user());

        return response()->json([
            'message' => 'นำเข้าข้อมูลผลสอบ N-NET สำเร็จ',
            'data' => $result,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403, 'ไม่มีสิทธิ์เพิ่มข้อมูล N-NET');

        $validated = $request->validate([
            'district_id' => ['nullable', 'integer'],
            'education_level' => ['required', 'integer', 'in:1,2,3'],
            'academic_year' => ['required', 'string', 'max:8'],
            'round' => ['required', 'integer', 'in:1,2'],
            'citizen_id' => ['required', 'string', 'max:32'],
            'student_name' => ['nullable', 'string', 'max:191'],
            'seat_no' => ['nullable', 'string', 'max:32'],
            'student_code' => ['nullable', 'string', 'max:64'],
            'group_code' => ['nullable', 'string', 'max:64'],
            'group_name' => ['nullable', 'string', 'max:191'],
            'has_score' => ['nullable', 'boolean'],
            'total_score' => ['nullable', 'numeric'],
            'subject_codes' => ['nullable', 'array'],
            'subject_names' => ['nullable', 'array'],
            'subject_scores' => ['nullable', 'array'],
            'subject_levels' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $record = $this->service->createRecord($validated, $request->user());

        return response()->json([
            'message' => 'เพิ่มข้อมูล N-NET สำเร็จ',
            'data' => $record,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $record = $this->service->getRecord($id, $request->user());

        return response()->json([
            'data' => $record,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403, 'ไม่มีสิทธิ์แก้ไขข้อมูล N-NET');

        $validated = $request->validate([
            'student_name' => ['nullable', 'string', 'max:191'],
            'seat_no' => ['nullable', 'string', 'max:32'],
            'student_code' => ['nullable', 'string', 'max:64'],
            'group_name' => ['nullable', 'string', 'max:191'],
            'has_score' => ['nullable', 'boolean'],
            'total_score' => ['nullable'],
            'subject_scores' => ['nullable', 'array'],
            'subject_levels' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $record = $this->service->updateRecord($id, $validated, $request->user());

        return response()->json([
            'message' => 'บันทึกการแก้ไขข้อมูลสำเร็จ',
            'data' => $record,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403, 'ไม่มีสิทธิ์ลบข้อมูล N-NET');

        $this->service->deleteRecord($id, $request->user());

        return response()->json([
            'message' => 'ลบข้อมูลผลสอบเรียบร้อยแล้ว',
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403, 'เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถล้างข้อมูลทั้งชุดได้');

        $filters = $request->validate([
            'district_id' => ['nullable', 'integer'],
            'education_level' => ['nullable', 'integer', 'in:1,2,3'],
            'academic_year' => ['nullable', 'string', 'max:8'],
            'round' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $count = $this->service->clearRecords($filters, $request->user());

        return response()->json([
            'message' => "ล้างข้อมูลเรียบร้อยแล้วทั้งหมด {$count} รายการ",
            'deleted_count' => $count,
        ]);
    }
}
