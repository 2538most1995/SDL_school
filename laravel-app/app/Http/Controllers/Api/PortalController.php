<?php

namespace App\Http\Controllers\Api;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Support\AcademicTerm;
use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortalController extends Controller
{
    public function __construct(private readonly StudentDirectoryService $students) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $districtId = (int) $request->attributes->get('district_id');
        $districtName = District::query()->whereKey($districtId)->value('name') ?? 'ไม่ระบุพื้นที่';
        $accessibleStudents = $this->students->accessibleStudents($user);
        $student = $user->role === 'student' ? ($accessibleStudents[0] ?? null) : null;

        $summary = $student === null
            ? [
                ['label' => 'นักศึกษาในขอบเขต', 'value' => number_format(count($accessibleStudents)), 'hint' => 'ข้อมูลจาก batch ปัจจุบัน'],
                ['label' => 'กลุ่มที่รับผิดชอบ', 'value' => number_format(count($user->assigned_groups ?? [])), 'hint' => $user->role === 'teacher' ? 'จำกัดตามสิทธิ์ครู' : 'ดูแลระดับอำเภอ'],
            ]
            : [
                ['label' => 'หน่วยกิตสะสม', 'value' => number_format($student->creditsEarned, 1), 'hint' => 'คำนวณจากผลการเรียนจริง'],
                ['label' => 'กิจกรรม กพช.', 'value' => number_format($student->kpchHours, 1), 'hint' => 'ชั่วโมงสะสม'],
                ['label' => 'ผลการเรียน', 'value' => number_format($student->gpax, 2), 'hint' => "ภาคเรียน {$student->currentTerm}"],
                ['label' => 'สถานะ', 'value' => $student->statusLabel, 'hint' => $student->levelLabel],
            ];

        return response()->json([
            'data' => [
                'mode' => 'production',
                'viewer' => [
                    'name' => $user->displayName(),
                    'role' => $user->role,
                    'district' => $districtName,
                ],
                'summary' => $summary,
                'analytics' => $this->analytics($accessibleStudents),
                'upcoming' => [],
                'modules' => [],
            ],
            'meta' => [
                'data_source' => config('legacy.enabled') ? 'legacy_read_only' : 'local',
                'district_id' => $districtId,
                'generated_at' => now()->toIso8601String(),
                'contains_personal_data' => false,
            ],
        ]);
    }

    /**
     * Build every dashboard chart from the same access-scoped student dataset.
     *
     * @param  list<Student>  $students
     * @return array<string, mixed>
     */
    private function analytics(array $students): array
    {
        $statuses = [
            'studying' => ['label' => 'กำลังศึกษา', 'value' => 0],
            'graduated' => ['label' => 'จบการศึกษา', 'value' => 0],
            'transferred' => ['label' => 'ย้ายสถานศึกษา', 'value' => 0],
            'inactive' => ['label' => 'พ้นสภาพ/รอตรวจสอบ', 'value' => 0],
        ];
        $levels = [
            1 => ['label' => 'ประถมศึกษา', 'value' => 0],
            2 => ['label' => 'มัธยมศึกษาตอนต้น', 'value' => 0],
            3 => ['label' => 'มัธยมศึกษาตอนปลาย', 'value' => 0],
        ];
        $genders = [
            'male' => ['label' => 'ชาย', 'value' => 0],
            'female' => ['label' => 'หญิง', 'value' => 0],
            'unspecified' => ['label' => 'ไม่ระบุ', 'value' => 0],
        ];
        $moral = [
            'excellent' => ['label' => 'ดีมาก', 'value' => 0],
            'good' => ['label' => 'ดี', 'value' => 0],
            'passed' => ['label' => 'ผ่าน/พอใช้', 'value' => 0],
            'improvement' => ['label' => 'ปรับปรุง', 'value' => 0],
            'pending' => ['label' => 'ยังไม่มีผล', 'value' => 0],
        ];
        $groups = [];
        $terms = [];
        $gpaxTotal = 0.0;
        $gpaxCount = 0;
        $creditsEarned = 0.0;
        $creditsRequired = 0.0;
        $kpchHours = 0.0;

        foreach ($students as $student) {
            if (isset($statuses[$student->status])) {
                $statuses[$student->status]['value']++;
            }
            if (isset($levels[$student->level])) {
                $levels[$student->level]['value']++;
            }

            $gender = (string) ($student->demographics['gender'] ?? '');
            $genderKey = match ($gender) {
                'ชาย' => 'male',
                'หญิง' => 'female',
                default => 'unspecified',
            };
            $genders[$genderKey]['value']++;

            $moralKey = match ($student->moralResult) {
                'ดีเยี่ยม', 'ดีมาก' => 'excellent',
                'ดี' => 'good',
                'ผ่าน', 'พอใช้' => 'passed',
                'ปรับปรุง' => 'improvement',
                default => 'pending',
            };
            $moral[$moralKey]['value']++;

            $groupName = trim($student->groupName) ?: trim($student->groupCode) ?: 'ไม่ระบุกลุ่ม';
            $groups[$groupName] = ($groups[$groupName] ?? 0) + 1;
            $terms[$student->currentTerm] = ($terms[$student->currentTerm] ?? 0) + 1;

            if ($student->gpax > 0) {
                $gpaxTotal += $student->gpax;
                $gpaxCount++;
            }
            $creditsEarned += $student->creditsEarned;
            $creditsRequired += $student->creditsRequired;
            $kpchHours += $student->kpchHours;
        }

        arsort($groups, SORT_NUMERIC);
        arsort($terms, SORT_NUMERIC);
        $count = count($students);
        $currentTerm = array_key_first($terms);
        $normalizedCurrentTerm = AcademicTerm::normalize($currentTerm);
        $newStudents = $normalizedCurrentTerm === null
            ? 0
            : count(array_filter(
                $students,
                static fn (Student $student): bool => self::enrollmentTerm($student) === $normalizedCurrentTerm,
            ));

        return [
            'totals' => [
                'students' => $count,
                'groups' => count($groups),
                'new_students' => $newStudents,
                'studying' => $statuses['studying']['value'],
                'graduated' => $statuses['graduated']['value'],
                'transferred' => $statuses['transferred']['value'],
                'inactive' => $statuses['inactive']['value'],
            ],
            'averages' => [
                'gpax' => $gpaxCount > 0 ? round($gpaxTotal / $gpaxCount, 2) : null,
                'credits_earned' => $count > 0 ? round($creditsEarned / $count, 1) : null,
                'credits_required' => $count > 0 ? round($creditsRequired / $count, 1) : null,
                'credit_progress_percent' => $creditsRequired > 0 ? round(min(100, ($creditsEarned / $creditsRequired) * 100), 1) : null,
                'kpch_hours' => $count > 0 ? round($kpchHours / $count, 1) : null,
            ],
            'current_term' => $currentTerm,
            'by_status' => array_values($statuses),
            'by_level' => array_values($levels),
            'by_gender' => array_values($genders),
            'by_group' => array_map(
                static fn (string $label, int $value): array => ['label' => $label, 'value' => $value],
                array_keys($groups),
                array_values($groups),
            ),
            'moral' => array_values($moral),
        ];
    }

    private static function enrollmentTerm(Student $student): ?string
    {
        if (preg_match('/^(\d{2})([12])/', $student->code, $matches) === 1) {
            return $matches[2].'/25'.$matches[1];
        }

        return AcademicTerm::normalize($student->enrollmentTerm);
    }
}
