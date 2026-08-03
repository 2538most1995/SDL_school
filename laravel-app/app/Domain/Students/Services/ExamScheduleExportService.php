<?php

namespace App\Domain\Students\Services;

use App\Models\User;
use App\Services\Legacy\LegacyExamScheduleService;

final readonly class ExamScheduleExportService
{
    private const MAX_STUDENTS = 500;

    public function __construct(
        private StudentDirectoryService $directory,
        private LegacyExamScheduleService $schedules,
    ) {}

    /** @param array{scope: string, student?: string, group?: string, level?: int} $filters
     * @return array{documents: list<array<string, mixed>>, scope: string, count: int}
     */
    public function build(User $viewer, array $filters): array
    {
        $scope = $filters['scope'];
        if ($scope === 'student') {
            $student = $this->directory->findAccessible($viewer, (string) ($filters['student'] ?? ''));
            abort_if($student === null, 404, 'ไม่พบข้อมูลตารางสอบ');

            return ['documents' => [$this->schedules->forStudent($student)], 'scope' => $scope, 'count' => 1];
        }

        abort_if($viewer->role === 'student', 403, 'ไม่มีสิทธิ์สร้างตารางสอบแบบกลุ่ม');
        $students = array_values(array_filter(
            $this->directory->accessibleStudents($viewer),
            static fn ($student): bool => $student->status === 'studying',
        ));
        $level = isset($filters['level']) ? (int) $filters['level'] : null;
        $group = trim((string) ($filters['group'] ?? ''));
        $students = array_values(array_filter($students, static function ($student) use ($scope, $level, $group): bool {
            if ($level !== null && $student->level !== $level) {
                return false;
            }
            if ($scope === 'group') {
                return $group !== '' && in_array($group, [$student->groupCode, $student->groupName], true);
            }

            return $scope === 'level' && $level !== null;
        }));
        abort_if($students === [], 404, 'ไม่พบนักศึกษาในขอบเขตที่เลือก');
        abort_if(count($students) > self::MAX_STUDENTS, 422, 'จำนวนนักศึกษาเกิน 500 คน กรุณาเลือกกลุ่มหรือระดับที่เล็กลง');
        usort($students, static fn ($left, $right): int => [$left->level, $left->groupName, $left->code] <=> [$right->level, $right->groupName, $right->code]);

        return [
            'documents' => array_map($this->schedules->forStudent(...), $students),
            'scope' => $scope,
            'count' => count($students),
        ];
    }
}
