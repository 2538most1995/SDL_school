<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NnetScheduleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $districtId = (int) $request->attributes->get('district_id');
        $district = District::query()->find($districtId);

        return response()->json([
            'data' => [
                'nnet_exam_date' => $district?->nnet_exam_date,
                'nnet_exam_time' => $district?->nnet_exam_time,
                'nnet_exam_location' => $district?->nnet_exam_location,
                'nnet_exam_notes' => $district?->nnet_exam_notes,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $viewer = $request->user();
        if (in_array($viewer->role, ['student'], true)) {
            return response()->json(['message' => 'ไม่มีสิทธิ์ตั้งค่าวันเวลาสอบ N-NET'], 403);
        }

        $validated = $request->validate([
            'nnet_exam_date' => ['nullable', 'string', 'max:100'],
            'nnet_exam_time' => ['nullable', 'string', 'max:100'],
            'nnet_exam_location' => ['nullable', 'string', 'max:255'],
            'nnet_exam_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $districtId = (int) $request->attributes->get('district_id');
        $district = District::query()->find($districtId);

        if ($district === null) {
            return response()->json(['message' => 'ไม่พบข้อมูลอำเภอ'], 404);
        }

        $district->update([
            'nnet_exam_date' => $validated['nnet_exam_date'] ?? null,
            'nnet_exam_time' => $validated['nnet_exam_time'] ?? null,
            'nnet_exam_location' => $validated['nnet_exam_location'] ?? null,
            'nnet_exam_notes' => $validated['nnet_exam_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'บันทึกข้อมูลการสอบ N-NET สำเร็จ',
            'data' => [
                'nnet_exam_date' => $district->nnet_exam_date,
                'nnet_exam_time' => $district->nnet_exam_time,
                'nnet_exam_location' => $district->nnet_exam_location,
                'nnet_exam_notes' => $district->nnet_exam_notes,
            ],
        ]);
    }
}
