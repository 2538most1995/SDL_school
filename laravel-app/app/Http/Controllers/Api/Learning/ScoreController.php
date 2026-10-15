<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Learning\DemoLearningPortal;
use App\Domain\Learning\DemoResponseMeta;
use App\Domain\Students\Services\StudentAcademicService;
use App\Http\Controllers\Controller;
use App\Services\Learning\ImportedScoreEditService;
use App\Services\Learning\LearningScorebookService;
use App\Services\Legacy\LegacyPortalReadService;
use App\Support\DbfWriteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ScoreController extends Controller
{
    public function __invoke(
        Request $request,
        DemoLearningPortal $portal,
        LegacyPortalReadService $legacy,
        LearningScorebookService $scorebooks,
        StudentAcademicService $academics,
    ): JsonResponse {
        $systemEnabled = (bool) config('system_data.enabled');
        $data = $systemEnabled && $request->user()?->role !== 'student'
            ? $legacy->scores($request->user(), (int) $request->attributes->get('district_id'))
            : $portal->scores();
        if (! $systemEnabled && $request->user()?->role === 'student') {
            $scorebookData = $scorebooks->studentScores($request->user(), (int) $request->attributes->get('district_id'));
            if ($scorebookData['courses'] !== []) {
                $data = $scorebookData;
            }
        }

        if ($systemEnabled && $request->user()?->role === 'student') {
            $studentGrades = $academics->grades($request->user(), (string) ($request->user()->student_code ?: $request->user()->username));
            $items = $studentGrades['items'] ?? [];
            $term = $studentGrades['summary']['term_summaries'][0]['term'] ?? null;
            $courses = [];
            foreach ($items as $index => $grade) {
                if ($grade->term !== $term) {
                    continue;
                }
                $courses[] = ['id' => 'imported-'.$index, 'subject_code' => $grade->subjectCode,
                    'subject_name' => $grade->subjectName, 'credits' => $grade->credits,
                    'assignment_score' => $grade->courseworkScore, 'exam_score' => $grade->finalExamScore,
                    'total_score' => $grade->totalScore, 'grade' => $grade->grade, 'maximum_score' => 100,
                    'status' => $grade->grade === null ? 'รอ ITW คำนวณเกรด' : 'เกรด '.$grade->grade];
            }
            $data = ['term' => $term, 'courses' => $courses,
                'summary' => ['score' => array_sum(array_column($courses, 'total_score')), 'maximum_score' => count($courses) * 100, 'items' => count($courses)],
                'disclaimer' => 'คะแนนจากข้อมูล ITW ที่นำเข้าและแก้ไขล่าสุดในระบบ'];
        }

        return response()->json([
            'data' => $data,
            'meta' => $systemEnabled ? ['mode' => 'production', 'source' => 'system_database', 'read_only' => true] : DemoResponseMeta::item(),
        ]);
    }

    public function workspace(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'regex:/^[12]\/25\d{2}$/'],
            'subject_code' => ['nullable', 'string', 'max:32'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'scorebook_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $scorebooks->workspace($request->user(), $this->districtId($request), $filters),
            'meta' => ['mode' => 'production', 'source' => 'system_database', 'read_only' => ! (bool) config('system_data.write_enabled')],
        ]);
    }

    public function imported(Request $request, StudentAcademicService $academics): JsonResponse
    {
        $filters = $request->validate([
            'term' => ['nullable', 'string', 'max:20'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'subject_code' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
            'calculation_status' => ['nullable', 'string', Rule::in(['incorrect'])],
            'midterm_status' => ['nullable', 'string', Rule::in(['below_40'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $data = $academics->importedScores($request->user(), $filters);
        $batch = config('system_data.enabled') ? ImportedScoreEditService::currentBatch($this->districtId($request)) : null;
        $data['batch_key'] = $batch;
        $data['can_edit'] = $batch !== null && (bool) config('system_data.student_enabled') && (bool) config('system_data.write_enabled') && Schema::hasTable('imported_score_edits');
        $data['can_bulk_edit'] = $data['can_edit'] && Schema::hasTable('imported_score_completions');
        $completed = $batch !== null && Schema::hasTable('imported_score_completions')
            ? DB::table('imported_score_completions')->where('district_id', $this->districtId($request))->where('batch_key', $batch)
                ->whereIn('score_key', array_map([ImportedScoreEditService::class, 'scoreKey'], $data['rows']))->pluck('score_key')->flip()->all() : [];
        foreach ($data['rows'] as &$row) {
            $row['entry_completed'] = isset($completed[ImportedScoreEditService::scoreKey($row)]);
            $row['edit_token'] = $batch === null ? null : ImportedScoreEditService::token($row, $batch);
        }
        unset($row);

        return response()->json(['data' => $data]);
    }

    public function updateImported(Request $request, ImportedScoreEditService $scores): JsonResponse
    {
        $input = $request->validate([
            'batch_key' => ['required', 'string', 'max:64', 'regex:/^import_\d{10}_[A-Za-z0-9]+$/D'],
            'student_code' => ['required', 'string', 'max:20'],
            'subject_code' => ['required', 'string', 'max:32'],
            'level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'academic_term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
            'edit_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
            'entry_mode' => ['sometimes', Rule::in(['bulk', 'individual'])],
            'assessment_scores' => ['required', 'array', 'size:9', 'list'],
            'assessment_scores.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'midterm_score' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'final_exam_score' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        try {
            return response()->json(['data' => $scores->save($request->user(), $this->districtId($request), $input, $request->ip())]);
        } catch (DbfWriteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);

            return response()->json(['message' => 'บันทึกคะแนนไม่สำเร็จ กรุณาโหลดข้อมูลใหม่หรือติดต่อผู้ดูแลระบบ'], 503);
        }
    }

    public function completeImported(Request $request, ImportedScoreEditService $scores): JsonResponse
    {
        $input = $request->validate([
            'batch_key' => ['required', 'string', 'max:64', 'regex:/^import_\d{10}_[A-Za-z0-9]+$/D'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'rows.*.student_code' => ['required', 'string', 'max:20'],
            'rows.*.subject_code' => ['required', 'string', 'max:32'],
            'rows.*.level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'rows.*.academic_term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
            'rows.*.edit_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
        ]);

        return response()->json(['data' => $scores->complete($request->user(), $this->districtId($request), $input, $request->ip())]);
    }

    public function store(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
            'subject_code' => ['required', 'string', 'max:32'],
            'level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'group' => ['nullable', 'string', 'max:120'],
            'score_ratio' => ['required', 'string', Rule::in(['60:40', '70:30', '80:20'])],
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.category' => ['required', 'string', Rule::in(['coursework', 'final_exam'])],
            'components.*.title' => ['required', 'string', 'max:120'],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->create($request->user(), $this->districtId($request), $values, $request->ip()),
        ], 201);
    }

    public function templates(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        return response()->json([
            'data' => $scorebooks->templates($request->user(), $this->districtId($request)),
        ]);
    }

    public function storeTemplate(Request $request, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'score_ratio' => ['required', 'string', Rule::in(['60:40', '70:30', '80:20'])],
            'applies_to_all' => ['required', 'boolean'],
            'subject_codes' => ['required_if:applies_to_all,false', 'array', 'max:100'],
            'subject_codes.*' => ['required', 'string', 'max:32', 'distinct'],
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.category' => ['required', 'string', Rule::in(['coursework', 'final_exam'])],
            'components.*.title' => ['required', 'string', 'max:120'],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->createTemplate($request->user(), $this->districtId($request), $values, $request->ip()),
        ], 201);
    }

    public function destroyTemplate(Request $request, int $template, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $scorebooks->deleteTemplate($request->user(), $this->districtId($request), $template, $request->ip());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function applyTemplate(Request $request, int $template, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'term' => ['required', 'regex:/^[12]\/25\d{2}$/'],
        ]);

        return response()->json([
            'data' => $scorebooks->applyTemplateToSubjects(
                $request->user(),
                $this->districtId($request),
                $template,
                $values['term'],
                $request->ip(),
            ),
        ]);
    }

    public function structure(Request $request, int $scorebook, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'score_ratio' => ['required', 'string', Rule::in(['60:40', '70:30', '80:20'])],
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'components.*.category' => ['required', 'string', Rule::in(['coursework', 'final_exam'])],
            'components.*.title' => ['required', 'string', 'max:120'],
            'components.*.max_score' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->updateStructure(
                $request->user(),
                $this->districtId($request),
                $scorebook,
                $values['score_ratio'],
                $values['components'],
                $request->ip(),
            ),
        ]);
    }

    public function entries(Request $request, int $scorebook, LearningScorebookService $scorebooks): JsonResponse
    {
        $this->assertWriteEnabled();
        $values = $request->validate([
            'students' => ['required', 'array', 'max:1000'],
            'students.*.student_code' => ['required', 'string', 'max:64', 'distinct'],
            'students.*.note' => ['nullable', 'string', 'max:2000'],
            'students.*.scores' => ['required', 'array', 'max:20'],
            'students.*.scores.*.component_id' => ['required', 'integer', 'min:1'],
            'students.*.scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $scorebooks->saveEntries(
                $request->user(),
                $this->districtId($request),
                $scorebook,
                $values['students'],
                $request->ip(),
            ),
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
}
