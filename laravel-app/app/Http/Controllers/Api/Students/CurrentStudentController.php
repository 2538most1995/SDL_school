<?php

namespace App\Http\Controllers\Api\Students;

use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Services\StudentAcademicService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentStudentController extends Controller
{
    public function __construct(
        private readonly StudentDirectoryService $directory,
        private readonly StudentAcademicService $academics,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $student = $this->currentStudent($request->user());
        abort_if($student === null, 404, 'ไม่พบข้อมูลนักศึกษาในขอบเขตที่รับผิดชอบ');

        return $this->demoResponse([
            'name' => $student->fullName(),
            'code' => $student->code,
            'level' => $student->levelLabel,
            'group' => $student->groupName,
            'advisor' => 'ตรวจสอบกับครูประจำกลุ่ม',
            'currentTerm' => $student->currentTerm,
            'nextMeeting' => 'ดูวันนัดหมายล่าสุดจากปฏิทินการเรียนรู้',
            'social' => [
                'facebook_url' => $student->facebookUrlFormatted(),
                'facebook_raw' => $student->facebookUrl,
                'line_id' => $student->lineId,
                'line_url' => $student->lineUrl(),
            ],
        ]);
    }

    public function grades(Request $request): JsonResponse
    {
        $filters = $request->validate(['term' => ['nullable', 'regex:/^[12]\/25\d{2}$/']]);
        $student = $this->currentStudent($request->user());
        abort_if($student === null, 404, 'ไม่พบข้อมูลนักศึกษาในขอบเขตที่รับผิดชอบ');
        $result = $this->academics->grades($request->user(), $student->code, $filters['term'] ?? null);
        abort_if($result === null, 404, 'ไม่พบข้อมูลผลการเรียน');

        return $this->demoResponse(array_map(
            static fn ($grade): array => [
                'code' => $grade->subjectCode,
                'subject' => $grade->subjectName,
                'credits' => $grade->credits,
                'type' => $grade->subjectType,
                'grade' => $grade->grade ?? '-',
                'term' => $grade->term,
            ],
            $result['items'],
        ));
    }

    public function kpch(Request $request): JsonResponse
    {
        $student = $this->currentStudent($request->user());
        abort_if($student === null, 404, 'ไม่พบข้อมูลนักศึกษาในขอบเขตที่รับผิดชอบ');
        $result = $this->academics->kpch($request->user(), $student->code);
        abort_if($result === null, 404, 'ไม่พบข้อมูลกิจกรรม กพช.');

        return $this->demoResponse(array_map(
            static fn ($activity): array => [
                'term' => $activity->term,
                'item' => $activity->name,
                'result' => number_format($activity->hours, 1).' ชั่วโมง',
                'note' => 'บันทึกจากข้อมูลต้นทาง',
                'hours' => $activity->hours,
                'category' => $activity->category,
            ],
            $result['items'],
        ));
    }

    public function moral(Request $request): JsonResponse
    {
        $student = $this->currentStudent($request->user());
        abort_if($student === null, 404, 'ไม่พบข้อมูลนักศึกษาในขอบเขตที่รับผิดชอบ');
        $result = $this->academics->moral($request->user(), $student->code);
        abort_if($result === null, 404, 'ไม่พบข้อมูลผลประเมินคุณธรรม');

        return $this->demoResponse(array_values(array_reduce(
            $result['items'],
            function (array $rows, MoralAssessment $assessment): array {
                foreach ($assessment->categories as $category) {
                    foreach ((array) ($category['items'] ?? []) as $item) {
                        $score = isset($item['score']) ? (float) $item['score'] : null;
                        $rows[] = [
                            'term' => $assessment->term,
                            'group' => (string) $category['name'],
                            'item' => (string) ($item['label'] ?? '-'),
                            'result' => $score === null ? '-' : number_format($score, 2),
                            'note' => $score === null ? 'ไม่มีผลประเมิน' : $this->moralLabel($score),
                            'score' => $score,
                        ];
                    }
                }

                return $rows;
            },
            [],
        )));
    }

    private function moralLabel(float $score): string
    {
        return match (true) {
            $score >= 90 => 'ดีมาก',
            $score >= 70 => 'ดี',
            $score >= 50 => 'พอใช้',
            default => 'ปรับปรุง',
        };
    }

    private function currentStudent(User $viewer): mixed
    {
        if ($viewer->role === 'student') {
            return $this->directory->findAccessible($viewer, (string) ($viewer->student_code ?: $viewer->username));
        }

        return $this->directory->accessibleStudents($viewer)[0] ?? null;
    }

    private function demoResponse(mixed $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'demo' => (bool) config('sena.demo_mode'),
                'data_classification' => config('system_data.enabled') ? 'system_database' : 'synthetic_demo',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
