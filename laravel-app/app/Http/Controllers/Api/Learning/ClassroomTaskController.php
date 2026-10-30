<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\ClassroomTaskService;
use App\Services\Learning\ClassroomUnitContentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClassroomTaskController extends Controller
{
    public function __construct(private ClassroomTaskService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->service->ready();
        $values = $request->validate(['unit_id' => ClassroomUnitContentScope::rules(), 'subject_code' => ['nullable', 'string', 'max:32'], 'education_level' => ['required_with:subject_code', 'nullable', 'integer', 'in:1,2,3'], 'page' => ['nullable', 'integer', 'min:1']]);

        return $this->response($this->service->listing($request->user(), $this->district($request), $values));
    }

    public function options(Request $request): JsonResponse
    {
        $this->service->ready();
        $values = $request->validate([...$this->courseRules(), 'target_group' => ['nullable', 'string', 'max:120']]);

        return $this->response($this->service->options($request->user(), $this->district($request), $values));
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $values = $request->validate([...$this->courseRules(), 'title' => ['required', 'string', 'max:220', 'regex:/\S/u'],
            'instructions' => ['nullable', 'string', 'max:10000'], 'due_at' => ['required', 'date', 'after:now'],
            'target_group' => ['nullable', 'string', 'max:120'], 'selection_mode' => ['required', Rule::in(['all', 'selected'])],
            'activity_ids' => ['sometimes', 'array', 'max:200'], 'activity_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'assignment_ids' => ['sometimes', 'array', 'max:200'], 'assignment_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $id = $this->service->create($request->user(), $this->district($request), $values);
        AuditService::logFromRequest($request, 'learning.classroom.task_created', 'learning_classroom_task', $id);

        return $this->response(['id' => (string) $id], 201);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        $this->service->ready();
        $values = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(['all', 'pending', 'in_progress', 'completed'])]]);

        return $this->response($this->service->detail($request->user(), $this->district($request), $task, $values['page'] ?? 1, $values['status'] ?? 'all'));
    }

    public function complete(Request $request, int $task, int $activity): JsonResponse
    {
        $this->write($request);
        $this->service->complete($request->user(), $this->district($request), $task, $activity);
        AuditService::logFromRequest($request, 'learning.classroom.content_completed', 'learning_classroom_task', $task);

        return $this->response(['completed' => true]);
    }

    private function courseRules(): array
    {
        return ['unit_id' => ClassroomUnitContentScope::rules(), 'subject_code' => ['required', 'string', 'max:32'], 'education_level' => ['required', 'integer', 'in:1,2,3']];
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function write(Request $request): void
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $this->service->ready();
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['read_only' => ! (bool) config('system_data.write_enabled')]], $status)->header('Cache-Control', 'private, no-store');
    }
}
