<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\ClassroomLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassroomLibraryController extends Controller
{
    public function __construct(private ClassroomLibraryService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listing($request);
    }

    public function sources(Request $request): JsonResponse
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $sources = false): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $this->service->listing($request->user(), $this->district($request), $filters, $sources)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $values = $request->validate(['unit_id' => ['required', 'max:1024', 'regex:/^(?:[1-9][0-9]*|legacy:[A-Za-z0-9_-]+)$/'], 'title' => ['nullable', 'string', 'max:220', 'regex:/\S/u']]);
        $id = $this->service->save($request->user(), $this->district($request), (string) $values['unit_id'], $values['title'] ?? null);
        AuditService::logFromRequest($request, 'learning.classroom.library.saved', 'learning_classroom_template', $id);

        return response()->json(['data' => ['id' => (string) $id]], 201);
    }

    public function restore(Request $request, int $template): JsonResponse
    {
        $this->write($request);
        $values = $request->validate(['subject_code' => ['required', 'string', 'max:32'], 'education_level' => ['required', 'integer', 'in:1,2,3'],
            'target_group' => ['present', 'nullable', 'string', 'max:120'], 'title' => ['nullable', 'string', 'max:220', 'regex:/\S/u']]);
        $id = $this->service->restore($request->user(), $this->district($request), $template, $values);
        AuditService::logFromRequest($request, 'learning.classroom.library.restored', 'learning_classroom_unit', $id);

        return response()->json(['data' => ['unit_id' => (string) $id]], 201);
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->write($request);
        $this->service->delete($request->user(), $this->district($request), $template);
        AuditService::logFromRequest($request, 'learning.classroom.library.deleted', 'learning_classroom_template', $template);

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function write(Request $request): void
    {
        $this->service->ready($request->user());
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }
}
