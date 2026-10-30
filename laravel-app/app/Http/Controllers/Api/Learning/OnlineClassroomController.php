<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Learning\OnlineClassroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OnlineClassroomController extends Controller
{
    public function __construct(private OnlineClassroomService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->service->ready();
        $filters = $request->validate(['subject_code' => ['nullable', 'string', 'max:32'], 'education_level' => ['required_with:subject_code', 'nullable', 'integer', 'in:1,2,3'],
            'unit_id' => ['nullable', 'regex:/^(legacy|[1-9][0-9]*)$/'], 'activity_id' => ['nullable', 'integer', 'min:1'], 'activity_type' => ['nullable', Rule::in(['knowledge', 'video', 'worksheet', 'quiz'])],
            'page' => ['nullable', 'integer', 'min:1'], 'activity_page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $this->service->workspace($request->user(), $this->district($request), $filters),
            'meta' => ['read_only' => ! (bool) config('system_data.write_enabled')]])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $id = $this->service->save($request->user(), $this->district($request), $this->values($request), null);
        $this->audit($request, 'created', $id);

        return response()->json(['data' => ['id' => (string) $id]], 201);
    }

    public function update(Request $request, int $activity): JsonResponse
    {
        $this->write($request);
        $id = $this->service->save($request->user(), $this->district($request), $this->values($request, true), $activity);
        $this->audit($request, 'updated', $id);

        return response()->json(['data' => ['id' => (string) $id]]);
    }

    public function destroy(Request $request, int $activity): JsonResponse
    {
        $this->write($request);
        $this->service->delete($request->user(), $this->district($request), $activity);
        $this->audit($request, 'deleted', $activity);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function submit(Request $request, int $activity): JsonResponse
    {
        $this->write($request, true);
        $values = $request->validate(['answers' => ['required', 'array', 'max:100'], 'answers.*' => ['required'],
            'answers.*.*' => ['string', 'max:10000']]);
        foreach ($values['answers'] as $answer) {
            abort_unless((is_string($answer) && mb_strlen($answer) <= 10000) || (is_array($answer) && count($answer) <= 10), 422, 'รูปแบบคำตอบไม่ถูกต้อง');
        }
        $saved = $this->service->submit($request->user(), $this->district($request), $activity, $values['answers']);
        $this->audit($request, 'submitted', $activity);

        return response()->json(['data' => $saved]);
    }

    public function review(Request $request, int $activity, int $submission): JsonResponse
    {
        $this->write($request);
        $values = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'], 'feedback' => ['nullable', 'string', 'max:5000']]);
        $saved = $this->service->review($request->user(), $this->district($request), $activity, $submission, $values);
        $this->audit($request, 'reviewed', $activity);

        return response()->json(['data' => $saved]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->write($request);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000']]);
        $file = $request->file('image');
        try {
            $path = $file->store('classroom/'.$this->district($request), 'local');
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['image' => 'เซิร์ฟเวอร์จัดเก็บภาพไม่ได้ กรุณาตรวจสิทธิ์เขียน private storage']);
        }
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['image' => 'เซิร์ฟเวอร์จัดเก็บภาพไม่ได้ กรุณาตรวจสิทธิ์เขียน private storage']);
        }
        try {
            $id = DB::table('learning_classroom_images')->insertGetId(['district_id' => $this->district($request), 'created_by' => $request->user()->id,
                'path' => $path, 'mime' => $file->getMimeType(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $this->audit($request, 'image_uploaded', $id);

        return response()->json(['data' => ['id' => (string) $id, 'url' => '/api/v1/learning/classroom/images/'.$id]], 201);
    }

    public function image(Request $request, int $image)
    {
        $this->service->ready();
        $row = DB::table('learning_classroom_images')->where('district_id', $this->district($request))->where('id', $image)->first();
        abort_if($row === null, 404);
        if ($row->activity_id !== null) {
            $activity = $this->service->authorizedActivity($request->user(), $this->district($request), (int) $row->activity_id);
            $questions = json_decode($activity->questions, true) ?? [];
            abort_unless(in_array((string) $image, array_map('strval', array_column($questions, 'image_id')), true), 404);
        } else {
            abort_unless((int) $row->created_by === (int) $request->user()->id && $request->user()->role !== 'student', 404);
        }
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, null, ['Content-Type' => $row->mime,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"], 'inline');
    }

    private function values(Request $request, bool $allowStatusOnly = false): array
    {
        if ($allowStatusOnly && array_diff(array_keys($request->all()), ['status']) === []) {
            return $request->validate(['status' => ['required', Rule::in(['draft', 'published'])]]);
        }

        return $request->validate([
            'subject_code' => ['required', 'string', 'max:32'], 'education_level' => ['required', 'integer', 'in:1,2,3'],
            'unit_id' => ['sometimes', 'nullable', 'regex:/^(legacy|[1-9][0-9]*)$/'], 'target_group' => ['nullable', 'string', 'max:120'], 'type' => ['required', Rule::in(['knowledge', 'video', 'worksheet', 'quiz'])],
            'title' => ['required', 'string', 'max:220', 'regex:/\S/u'], 'content' => ['nullable', 'string', 'max:30000'],
            'status' => ['required', Rule::in(['draft', 'published'])], 'due_at' => ['nullable', 'date'],
            'links' => ['present', 'array', 'max:20', 'min:'.($request->input('type') === 'video' ? 1 : 0)], 'links.*' => ['array:title,url'], 'links.*.title' => ['required', 'string', 'max:220'],
            'links.*.url' => ['required', 'url:http,https', 'max:2048', function ($attribute, $value, $fail) {
                if (preg_match('/[\x00-\x20\x7f\\\\]/', $value) || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
                    $fail('ลิงก์ต้องเป็น HTTP/HTTPS และไม่มีข้อมูลเข้าสู่ระบบ');
                }
            }],
            'questions' => ['present', 'array', $request->input('type') === 'video' ? 'size:0' : 'max:100'], 'questions.*' => ['array:id,type,prompt,image_id,options,points,answer'],
            'questions.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', 'not_in:__proto__,constructor,prototype'],
            'questions.*.type' => ['required', Rule::in(['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay'])],
            'questions.*.prompt' => ['required', 'string', 'max:5000', 'regex:/\S/u'], 'questions.*.image_id' => ['nullable', 'integer', 'min:1'],
            'questions.*.points' => ['required', 'numeric', 'min:0.01', 'max:1000', 'decimal:0,2'],
            'questions.*.options' => ['present', 'array', 'max:10'], 'questions.*.options.*' => ['required', 'string', 'max:1000'],
            'questions.*.answer' => ['nullable', 'max:10000'], 'questions.*.answer.*' => ['string', 'max:1000'],
        ]);
    }

    private function write(Request $request, bool $student = false): void
    {
        abort_unless($student ? $request->user()->role === 'student' : in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true), 403);
        abort_unless((bool) config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $this->service->ready();
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function audit(Request $request, string $action, int $id): void
    {
        AuditService::logFromRequest($request, 'learning.classroom.'.$action, 'learning_classroom_activity', $id);
    }
}
