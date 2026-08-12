<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\Learning\AssignmentWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $submissionType = $request->string('submission_type')->toString();
        $rules = [
            'submission_type' => ['required', Rule::in(['link', 'pdf', 'image'])],
            'url' => [Rule::requiredIf($submissionType === 'link'), 'nullable', 'url:http,https', 'max:2000'],
        ];
        if ($submissionType === 'pdf') {
            $rules['file'] = ['required', 'file', 'mimes:pdf', 'max:20480'];
        } elseif ($submissionType === 'image') {
            $rules['files'] = [Rule::requiredIf(! $request->hasFile('file')), 'nullable', 'array', 'min:1', 'max:10'];
            $rules['files.*'] = ['file', 'mimes:jpg,jpeg,png,webp', 'max:20480'];
            // Keep accepting the single-file field used by the previous UI.
            $rules['file'] = [Rule::requiredIf(! $request->hasFile('files')), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'];
        }
        $values = $request->validate($rules, [
            'file.mimes' => $submissionType === 'image' ? 'รูปภาพรองรับเฉพาะ JPG, PNG และ WebP' : 'เอกสารรองรับเฉพาะไฟล์ PDF',
            'file.max' => 'ไฟล์ต้องมีขนาดไม่เกิน 20 MB',
            'files.required' => 'กรุณาเลือกรูปภาพอย่างน้อย 1 รูป',
            'files.max' => 'ส่งรูปภาพได้ไม่เกิน 10 รูปต่อครั้ง',
            'files.*.mimes' => 'รูปภาพรองรับเฉพาะ JPG, PNG และ WebP',
            'files.*.max' => 'รูปภาพแต่ละไฟล์ต้องมีขนาดไม่เกิน 20 MB',
        ]);

        $files = [];
        if ($submissionType === 'pdf' && $request->hasFile('file')) {
            $files[] = $request->file('file');
        } elseif ($submissionType === 'image') {
            $multipleFiles = $request->file('files', []);
            $files = is_array($multipleFiles) ? array_values(array_filter($multipleFiles)) : [];
            if ($files === [] && $request->hasFile('file')) {
                $files[] = $request->file('file');
            }
            $totalBytes = array_sum(array_map(static fn ($file): int => (int) $file->getSize(), $files));
            if ($totalBytes > 50 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'files' => 'รูปภาพรวมกันต้องมีขนาดไม่เกิน 50 MB',
                ]);
            }
        }

        return response()->json([
            'data' => $workflow->submit(
                $request->user(),
                $this->districtId($request),
                $assignment,
                (string) $values['submission_type'],
                isset($values['url']) ? (string) $values['url'] : null,
                $files,
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

        return $this->privateFileResponse(
            (string) $row->attachment_path,
            (string) ($row->original_filename ?: 'assignment.pdf'),
        );
    }

    public function attachment(Request $request, int $assignment, int $submission, int $attachment, AssignmentWorkflowService $workflow)
    {
        $row = $workflow->submissionAttachmentForDownload(
            $request->user(),
            $this->districtId($request),
            $assignment,
            $submission,
            $attachment,
        );

        return $this->privateFileResponse(
            (string) $row->storage_path,
            (string) ($row->original_filename ?: 'assignment-image.jpg'),
        );
    }

    public function material(Request $request, int $assignment, AssignmentWorkflowService $workflow)
    {
        $row = $workflow->materialForDownload(
            $request->user(),
            $this->districtId($request),
            $assignment,
        );

        return $this->privateFileResponse(
            (string) $row->material_path,
            (string) ($row->material_filename ?: 'assignment-material.pdf'),
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
            'material_pdf' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
            'remove_material_pdf' => ['sometimes', 'boolean'],
        ], [
            'due_at.after' => 'กำหนดส่งต้องอยู่หลังเวลาเปิดรับงาน',
            'material_url.url' => 'ลิงก์เอกสารงานต้องขึ้นต้นด้วย http:// หรือ https://',
            'material_pdf.mimes' => 'ไฟล์ประกอบงานรองรับ PDF, JPG, PNG และ WebP',
            'material_pdf.max' => 'ไฟล์ประกอบงานต้องมีขนาดไม่เกิน 20 MB',
        ]);
    }

    private function privateFileResponse(string $path, string $filename)
    {
        $disk = Storage::disk('local');
        $mimeType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
        abort_unless($mimeType !== null, 404);

        return $disk->response(
            $path,
            $filename,
            [
                'Content-Type' => $mimeType,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            'inline',
        );
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
