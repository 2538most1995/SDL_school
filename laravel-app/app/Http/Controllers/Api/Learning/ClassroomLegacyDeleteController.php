<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\ClassroomLegacyDeleteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassroomLegacyDeleteController extends Controller
{
    public function __invoke(Request $request, ClassroomLegacyDeleteService $service): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $values = $request->validate(['subject_code' => ['required', 'string', 'max:32'], 'education_level' => ['required', 'integer', 'in:1,2,3']]);
        $data = $service->delete($request->user(), (int) $request->attributes->get('district_id'), $values['subject_code'], (int) $values['education_level']);
        AuditService::logFromRequest($request, 'learning.classroom.legacy_deleted', 'learning_classroom_unit', null);

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
