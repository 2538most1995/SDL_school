<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Services\Legacy\LegacyExamScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentExamScheduleController extends StudentsApiController
{
    public function __invoke(Request $request, string $student, StudentDirectoryService $directory, LegacyExamScheduleService $schedule): JsonResponse
    {
        $record = $directory->findAccessible($request->user(), $student);
        abort_if($record === null, 404, 'ไม่พบข้อมูลนักศึกษา');

        return response()->json([
            'data' => $schedule->forStudent($record),
            'meta' => $this->meta(['contains_personal_data' => true, 'read_only' => true]),
        ])->header('Cache-Control', 'private, no-store');
    }
}
