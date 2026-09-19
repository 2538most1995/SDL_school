<?php

namespace App\Services\Learning;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Services\LegacyStudentReportService;
use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\ExamEligibilityStatistics;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final readonly class ExamAttendanceService
{
    public function __construct(
        private DatabaseManager $database,
        private LegacyStudentReportService $legacyReports,
        private StudentDirectoryService $directory,
        private StudentRepository $students,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function workspace(User $viewer, int $districtId, array $filters): array
    {
        $this->assertStaff($viewer);
        $source = $this->registrationSource($viewer, $districtId, $filters);
        $rows = $this->eligibleRegistrations($source['rows']);
        $level = isset($filters['level']) ? (int) $filters['level'] : null;
        $group = trim((string) ($filters['group'] ?? ''));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($level === null || (int) $row['level'] === $level)
            && ($group === '' || in_array($group, [(string) $row['group_code'], (string) $row['group_name']], true))));

        $subjects = $this->subjects($rows);
        $studentOptions = $this->studentOptions($rows);
        $view = (string) ($filters['view'] ?? 'subject');
        $selectedSubject = $this->selectedSubject($subjects, (string) ($filters['subject_code'] ?? ''), $level);
        $selectedStudent = $this->selectedStudent($studentOptions, (string) ($filters['student_code'] ?? ''), $level);
        $items = array_values(array_filter($rows, static function (array $row) use ($view, $selectedSubject, $selectedStudent): bool {
            if ($view === 'student') {
                return $selectedStudent !== null
                    && (string) $row['student_code'] === (string) $selectedStudent['student_code']
                    && (int) $row['level'] === (int) $selectedStudent['level'];
            }

            return $selectedSubject !== null
                && (string) $row['subject_code'] === (string) $selectedSubject['code']
                && (int) $row['level'] === (int) $selectedSubject['level'];
        }));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        if ($search !== '') {
            $items = array_values(array_filter($items, static fn (array $row): bool => str_contains(mb_strtolower(implode(' ', [
                $row['student_code'], $row['student_name'], $row['group_name'], $row['subject_code'], $row['subject_name'],
            ])), $search)));
        }

        $saved = $this->savedAttendance($districtId, (string) ($source['selected_term'] ?? ''));
        $items = array_map(static function (array $row) use ($saved): array {
            $key = self::key($row);
            $attendance = $saved[$key] ?? null;

            return [
                'student_code' => (string) $row['student_code'],
                'full_name' => (string) $row['student_name'],
                'level' => (int) $row['level'],
                'level_label' => self::levelLabel((int) $row['level']),
                'group_code' => (string) $row['group_code'],
                'group_name' => (string) $row['group_name'],
                'subject_code' => (string) $row['subject_code'],
                'subject_name' => (string) $row['subject_name'],
                'attended' => (bool) ($attendance?->attended ?? false),
                'recorded' => $attendance !== null,
                'checked_at' => $attendance?->checked_at,
            ];
        }, $items);
        usort($items, static fn (array $left, array $right): int => $view === 'student'
            ? strnatcasecmp($left['subject_code'], $right['subject_code'])
            : strnatcasecmp($left['student_code'], $right['student_code']));
        $attended = count(array_filter($items, static fn (array $item): bool => $item['attended']));
        $total = count($items);

        return [
            'view' => $view,
            'terms' => $source['terms'],
            'selected_term' => $source['selected_term'],
            'subjects' => $subjects,
            'students' => $studentOptions,
            'selected_subject' => $selectedSubject,
            'selected_student' => $selectedStudent,
            'items' => $items,
            'summary' => [
                'registered_students' => $total,
                'attended_students' => $attended,
                'absent_students' => $total - $attended,
                'attendance_rate' => $total > 0 ? round(($attended / $total) * 100, 1) : 0.0,
            ],
        ];
    }

    /** @param array<string, mixed> $values @return array{saved_records:int} */
    public function save(User $viewer, int $districtId, array $values, ?string $ipAddress): array
    {
        $this->assertStaff($viewer);
        $source = $this->registrationSource($viewer, $districtId, ['term' => $values['term']]);
        abort_unless($source['selected_term'] === $values['term'], 404);
        $allowed = [];
        foreach ($this->eligibleRegistrations($source['rows']) as $row) {
            $allowed[self::key($row)] = true;
        }

        $seen = [];
        foreach ($values['records'] as $index => $record) {
            $key = self::key($record);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["records.{$index}" => 'มีรายการเช็คชื่อซ้ำ']);
            }
            $seen[$key] = true;
            if (! isset($allowed[$key])) {
                throw ValidationException::withMessages(["records.{$index}" => 'นักศึกษาหรือรายวิชาอยู่นอกขอบเขตที่ได้รับอนุญาต']);
            }
        }

        $connection = $this->database->connection();
        $connection->transaction(function () use ($connection, $viewer, $districtId, $values, $ipAddress): void {
            foreach ($values['records'] as $record) {
                $connection->table('learning_exam_attendances')->updateOrInsert([
                    'district_id' => $districtId,
                    'academic_term' => $values['term'],
                    'subject_code' => $record['subject_code'],
                    'education_level' => $record['level'],
                    'student_code' => $record['student_code'],
                ], [
                    'attended' => (bool) $record['attended'],
                    'checked_by' => $viewer->id,
                    'checked_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $connection->table('audit_logs')->insert([
                'user_id' => $viewer->id,
                'district_id' => $districtId,
                'event' => 'learning.exam_attendance.saved',
                'auditable_type' => 'system_exam_attendance',
                'ip_address' => $ipAddress,
                'context' => json_encode(['term' => $values['term'], 'record_count' => count($values['records'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return ['saved_records' => count($values['records'])];
    }

    /** @param array<string, mixed> $filters @return array{terms:list<string>,selected_term:?string,rows:list<array<string,mixed>>} */
    private function registrationSource(User $viewer, int $districtId, array $filters): array
    {
        if ((bool) config('system_data.student_enabled')) {
            return $this->legacyReports->scorebookRegistrations($viewer, $districtId, ['term' => $filters['term'] ?? null]);
        }
        $students = $this->directory->accessibleStudents($viewer);
        $gradesByStudent = $this->students->gradesForMany($students);
        $terms = [];
        foreach ($gradesByStudent as $grades) {
            foreach ($grades as $grade) {
                $normalized = AcademicTerm::normalize($grade->term);
                if ($normalized !== null) {
                    $terms[$normalized] = true;
                }
            }
        }
        $terms = array_keys($terms);
        usort($terms, static fn (string $left, string $right): int => AcademicTerm::compare($right, $left));
        $selectedTerm = AcademicTerm::normalize((string) ($filters['term'] ?? '')) ?? ($terms[0] ?? null);
        $rows = [];
        foreach ($students as $student) {
            foreach ($gradesByStudent["{$student->districtId}|{$student->level}|{$student->code}"] ?? [] as $grade) {
                if ($selectedTerm !== null && AcademicTerm::normalize($grade->term) === $selectedTerm) {
                    $rows[] = $this->demoRow($student, $grade);
                }
            }
        }

        return ['terms' => $terms, 'selected_term' => $selectedTerm, 'rows' => $rows];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function eligibleRegistrations(array $rows): array
    {
        $disqualified = [];
        foreach ($rows as $row) {
            if (ExamEligibilityStatistics::isDisqualifyingStatus($row['grade_value'] ?? null)) {
                $disqualified[(int) $row['level'].'|'.(string) $row['student_code']] = true;
            }
        }

        return array_values(array_filter($rows, static fn (array $row): bool => ! isset($disqualified[(int) $row['level'].'|'.(string) $row['student_code']])));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function subjects(array $rows): array
    {
        $subjects = [];
        foreach ($rows as $row) {
            $key = (int) $row['level'].'|'.(string) $row['subject_code'];
            $subjects[$key] ??= ['code' => (string) $row['subject_code'], 'name' => (string) $row['subject_name'], 'level' => (int) $row['level'], 'level_label' => self::levelLabel((int) $row['level']), '_students' => [], '_groups' => []];
            $subjects[$key]['_students'][(string) $row['student_code']] = true;
            $subjects[$key]['_groups'][(string) $row['group_code']] = ['code' => (string) $row['group_code'], 'name' => (string) $row['group_name']];
        }
        $result = [];
        foreach ($subjects as $subject) {
            $result[] = [...array_diff_key($subject, ['_students' => true, '_groups' => true]), 'student_count' => count($subject['_students']), 'groups' => array_values($subject['_groups'])];
        }
        usort($result, static fn (array $a, array $b): int => [$a['level'], $a['code']] <=> [$b['level'], $b['code']]);

        return $result;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function studentOptions(array $rows): array
    {
        $students = [];
        foreach ($rows as $row) {
            $key = (int) $row['level'].'|'.(string) $row['student_code'];
            $students[$key] = ['student_code' => (string) $row['student_code'], 'full_name' => (string) $row['student_name'], 'level' => (int) $row['level'], 'level_label' => self::levelLabel((int) $row['level']), 'group_code' => (string) $row['group_code'], 'group_name' => (string) $row['group_name']];
        }
        $result = array_values($students);
        usort($result, static fn (array $a, array $b): int => strnatcasecmp($a['student_code'], $b['student_code']));

        return $result;
    }

    /** @param list<array<string,mixed>> $subjects */
    private function selectedSubject(array $subjects, string $code, ?int $level): ?array
    {
        foreach ($subjects as $subject) {
            if (($code === '' || $subject['code'] === $code) && ($level === null || $subject['level'] === $level)) {
                return $subject;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $students */
    private function selectedStudent(array $students, string $code, ?int $level): ?array
    {
        foreach ($students as $student) {
            if (($code === '' || $student['student_code'] === $code) && ($level === null || $student['level'] === $level)) {
                return $student;
            }
        }

        return null;
    }

    /** @return array<string,object> */
    private function savedAttendance(int $districtId, string $term): array
    {
        if ($term === '') {
            return [];
        }

        return $this->database->connection()->table('learning_exam_attendances')
            ->where('district_id', $districtId)->where('academic_term', $term)
            ->get(['education_level', 'student_code', 'subject_code', 'attended', 'checked_at'])
            ->keyBy(static fn (object $row): string => (int) $row->education_level.'|'.(string) $row->student_code.'|'.(string) $row->subject_code)
            ->all();
    }

    private function demoRow(Student $student, Grade $grade): array
    {
        return ['level' => $student->level, 'student_code' => $student->code, 'student_name' => $student->fullName(), 'group_code' => $student->groupCode, 'group_name' => $student->groupName, 'subject_code' => $grade->subjectCode, 'subject_name' => $grade->subjectName, 'grade_value' => $grade->grade];
    }

    /** @param array<string,mixed> $row */
    private static function key(array $row): string
    {
        return (int) $row['level'].'|'.(string) $row['student_code'].'|'.(string) $row['subject_code'];
    }

    private static function levelLabel(int $level): string
    {
        return [1 => 'ประถมศึกษา', 2 => 'มัธยมศึกษาตอนต้น', 3 => 'มัธยมศึกษาตอนปลาย'][$level] ?? 'ไม่ทราบระดับ';
    }

    private function assertStaff(User $viewer): void
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
    }
}
