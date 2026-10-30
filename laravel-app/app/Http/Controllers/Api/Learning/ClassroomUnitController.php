<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\ClassroomUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClassroomUnitController extends Controller
{
    public function __construct(private ClassroomUnitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['subject_code' => ['required', 'string', 'max:32'], 'education_level' => ['required', 'integer', 'in:1,2,3'], 'page' => ['nullable', 'integer', 'min:1']]);

        return $this->response($this->service->listing($request->user(), $this->district($request), $filters));
    }

    public function show(Request $request, int $unit): JsonResponse
    {
        return $this->response($this->service->detail($request->user(), $this->district($request), $unit));
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $id = $this->service->save($request->user(), $this->district($request), $this->values($request), null);
        AuditService::logFromRequest($request, 'learning.classroom.unit_created', 'learning_classroom_unit', $id);

        return $this->response(['id' => (string) $id], 201);
    }

    public function update(Request $request, int $unit): JsonResponse
    {
        $this->write($request);
        $id = $this->service->save($request->user(), $this->district($request), $this->values($request, true), $unit);
        AuditService::logFromRequest($request, 'learning.classroom.unit_updated', 'learning_classroom_unit', $id);

        return $this->response(['id' => (string) $id]);
    }

    public function destroy(Request $request, int $unit): JsonResponse
    {
        $this->write($request);
        $this->service->delete($request->user(), $this->district($request), $unit);
        AuditService::logFromRequest($request, 'learning.classroom.unit_deleted', 'learning_classroom_unit', $unit);

        return $this->response(['deleted' => true]);
    }

    private function values(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate(['subject_code' => [$required, 'string', 'max:32'], 'education_level' => [$required, 'integer', 'in:1,2,3'],
            'target_group' => ['sometimes', 'nullable', 'string', 'max:120'], 'title' => [$required, 'string', 'max:220', 'regex:/\S/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'status' => [$required, Rule::in(['draft', 'published'])]]);
    }

    private function write(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $this->service->ready();
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['read_only' => ! (bool) config('system_data.write_enabled')]], $status)->header('Cache-Control', 'private, no-store');
    }
}
