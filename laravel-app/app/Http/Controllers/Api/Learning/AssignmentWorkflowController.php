<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\AssignmentWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AssignmentWorkflowController extends Controller
{
    public function index(Request $request, AssignmentWorkflowService $workflow): JsonResponse
    {
        $filters = $request->validate([
            'assignment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $workflow->workspace(
                $request->user(),
                $this->districtId($request),
                isset($filters['assignment_id']) ? (int) $filters['assignment_id'] : null,
            ),
            'meta' => [
                'mode' => 'production',
                'source' => 'system_database',
                'roster_source' => 'current_term_registrations',
                'read_only' => ! (bool) config('system_data.write_enabled'),
            ],
        ]);
    }

    public function store(
        Request $request,
        AssignmentWorkflowService $workflow,
        LearningContentController $legacyContent,
    ): JsonResponse {
        if ($request->filled('subject') && ! $request->has('subject_code')) {
            return $legacyContent->store($request, 'assignments');
        }
        $this->assertWriteEnabled();
        $values = $this->assignmentValues($request);

        try {
            $saved = $workflow->saveAssignment(
                $request->user(),
                $this->districtId($request),
                $values,
                $request->file('material_pdf'),
                null,
                $request->ip(),
            );
        } catch (QueryException $exception) {
            return $this->databaseNotReady($exception);
        }

        return response()->json(['data' => $saved], 201);
    }

    public function update(
        Request $request,
        int $assignment,
        AssignmentWorkflowService $workflow,
        LearningContentController $legacyContent,
    ): JsonResponse {
        if ($request->filled('subject') && ! $request->has('subject_code')) {
            return $legacyContent->update($request, 'assignments', $assignment);
        }
        $this->assertWriteEnabled();

        try {
            $saved = $workflow->saveAssignment(
                $request->user(),
                $this->districtId($request),
                $this->assignmentValues($request),
                $request->file('material_pdf'),
                $assignment,
                $request->ip(),
            );
        } catch (QueryException $exception) {
            return $this->databaseNotReady($exception);
        }

        return response()->json(['data' => $saved]);
    }

    public function destroy(Request $request, int $assignment, AssignmentWorkflowService $workflow): JsonResponse
    {
        $this->assertWriteEnabled();
        $workflow->deleteAssignment(
            $request->user(),
            $this->districtId($request),
            $assignment,
            $request->ip(),
        );

        return response()->json(['data' => ['deleted' => true, 'id' => (string) $assignment]]);
    }

    public function submit(Request $request, int $assignment, AssignmentWorkflowService $workflow): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'submission_type' => ['required', Rule::in(['link', 'pdf'])],
            'url' => [Rule::requiredIf($request->input('submission_type') === 'link'), 'nullable', 'url:http,https', 'max:2000'],
            'file' => [Rule::requiredIf($request->input('submission_type') === 'pdf'), 'nullable', 'file', 'mimes:pdf', 'max:20480'],
        ], [
            'file.mimes' => 'รองรับเฉพาะไฟล์ PDF เท่านั้น',
            'file.max' => 'ไฟล์ PDF ต้องมีขนาดไม่เกิน 20 MB',
        ]);

        return response()->json([
            'data' => $workflow->submit(
                $request->user(),
                $this->districtId($request),
                $assignment,
                (string) $values['submission_type'],
                isset($values['url']) ? (string) $values['url'] : null,
                $request->file('file'),
                $request->ip(),
            ),
        ]);
    }

    public function review(Request $request, int $assignment, int $submission, AssignmentWorkflowService $workflow): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json([
            'data' => $workflow->review(
                $request->user(),
                $this->districtId($request),
                $assignment,
                $submission,
                isset($values['score']) ? (float) $values['score'] : null,
                isset($values['feedback']) ? (string) $values['feedback'] : null,
                $request->ip(),
            ),
        ]);
    }

    public function file(Request $request, int $assignment, int $submission, AssignmentWorkflowService $workflow)
    {
        $row = $workflow->submissionForDownload(
            $request->user(),
            $this->districtId($request),
            $assignment,
            $submission,
        );

        return Storage::disk('local')->download(
            (string) $row->attachment_path,
            (string) ($row->original_filename ?: 'assignment.pdf'),
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function material(Request $request, int $assignment, AssignmentWorkflowService $workflow)
    {
        $row = $workflow->materialForDownload(
            $request->user(),
            $this->districtId($request),
            $assignment,
        );

        return Storage::disk('local')->download(
            (string) $row->material_path,
            (string) ($row->material_filename ?: 'assignment-material.pdf'),
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /** @return array<string, mixed> */
    private function assignmentValues(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:220'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'subject_code' => ['required', 'string', 'max:32'],
            'education_level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'target_group' => ['nullable', 'string', 'max:120'],
            'max_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'opens_at' => ['nullable', 'date'],
            'due_at' => ['required', 'date', 'after:opens_at'],
            'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
            'material_url' => ['nullable', 'url:http,https', 'max:2000'],
            'material_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'remove_material_pdf' => ['sometimes', 'boolean'],
        ], [
            'due_at.after' => 'กำหนดส่งต้องอยู่หลังเวลาเปิดรับงาน',
            'material_url.url' => 'ลิงก์เอกสารงานต้องขึ้นต้นด้วย http:// หรือ https://',
            'material_pdf.mimes' => 'เอกสารงานรองรับเฉพาะไฟล์ PDF เท่านั้น',
            'material_pdf.max' => 'เอกสารงาน PDF ต้องมีขนาดไม่เกิน 20 MB',
        ]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function assertWriteEnabled(): void
    {
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
    }

    private function databaseNotReady(QueryException $exception): JsonResponse
    {
        report($exception);

        return response()->json([
            'message' => 'ฐานข้อมูลสำหรับงานและเอกสารยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force และ php artisan optimize:clear',
        ], 503);
    }
}
