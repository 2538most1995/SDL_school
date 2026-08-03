<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Models\User;

final readonly class StudentReportService
{
    public function __construct(
        private StudentRepository $repository,
        private StudentDirectoryService $directory,
    ) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function overview(User $viewer, array $filters = []): array
    {
        $students = $this->students($viewer, $filters);
        $count = count($students);
        $statusCounts = $this->countBy($students, static fn (Student $student): string => $student->status);
        $levelCounts = $this->countBy($students, static fn (Student $student): string => (string) $student->level);

        return [
            'totals' => [
                'students' => $count,
                'studying' => $statusCounts['studying'] ?? 0,
                'graduated' => $statusCounts['graduated'] ?? 0,
                'transferred' => $statusCounts['transferred'] ?? 0,
            ],
            'averages' => [
                'gpax' => $count > 0 ? round(array_sum(array_column($students, 'gpax')) / $count, 2) : null,
                'credits_earned' => $count > 0 ? round(array_sum(array_column($students, 'creditsEarned')) / $count, 1) : null,
                'kpch_hours' => $count > 0 ? round(array_sum(array_column($students, 'kpchHours')) / $count, 1) : null,
            ],
            'by_level' => [
                ['level' => 1, 'label' => 'ประถมศึกษา', 'students' => $levelCounts['1'] ?? 0],
                ['level' => 2, 'label' => 'มัธยมศึกษาตอนต้น', 'students' => $levelCounts['2'] ?? 0],
                ['level' => 3, 'label' => 'มัธยมศึกษาตอนปลาย', 'students' => $levelCounts['3'] ?? 0],
            ],
            'moral' => [
                'excellent' => count(array_filter($students, static fn (Student $student): bool => $student->moralResult === 'ดีเยี่ยม')),
                'good' => count(array_filter($students, static fn (Student $student): bool => $student->moralResult === 'ดี')),
                'passed' => count(array_filter($students, static fn (Student $student): bool => $student->moralResult === 'ผ่าน')),
            ],
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, active: int, groups: int, rows: list<array<string, string>>}
     */
    public function newStudents(User $viewer, array $filters = []): array
    {
        $term = (string) ($filters['term'] ?? '');
        $students = array_values(array_filter(
            $this->students($viewer, $filters),
            static fn (Student $student): bool => $term === '' || $student->enrollmentTerm === $term,
        ));
        $rows = array_map(static fn (Student $student): array => [
            'id' => "{$student->districtId}-{$student->level}-{$student->code}",
            'primary' => $student->fullName(),
            'secondary' => $student->code,
            'group' => $student->levelLabel.' · '.$student->groupName,
            'metric' => 'ภาคเรียน '.$student->enrollmentTerm,
            'status' => $student->statusLabel,
        ], $students);

        return ['total' => count($rows), 'active' => count(array_filter($students, static fn (Student $student): bool => $student->status === 'studying')), 'groups' => count(array_unique(array_column($rows, 'group'))), 'rows' => $rows];
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, active: int, groups: int, rows: list<array<string, string>>}
     */
    public function graduates(User $viewer, array $filters = []): array
    {
        $term = (string) ($filters['term'] ?? '');
        $students = array_values(array_filter(
            $this->students($viewer, $filters),
            static fn (Student $student): bool => $student->status === 'graduated' && ($term === '' || $student->currentTerm === $term),
        ));
        $rows = array_map(static fn (Student $student): array => [
            'id' => "{$student->districtId}-{$student->level}-{$student->code}",
            'primary' => $student->fullName(),
            'secondary' => $student->code,
            'group' => $student->levelLabel,
            'metric' => $student->currentTerm,
            'status' => 'จบการศึกษา',
        ], $students);

        return ['total' => count($rows), 'active' => count($rows), 'groups' => count(array_unique(array_column($rows, 'group'))), 'rows' => $rows];
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, active: int, groups: int, rows: list<array<string, string>>}
     */
    public function transfers(User $viewer, array $filters = []): array
    {
        $term = (string) ($filters['term'] ?? '');
        $rows = [];
        $students = $this->students($viewer, $filters);
        $gradesByStudent = $this->repository->gradesForMany($students);
        foreach ($students as $student) {
            foreach ($this->studentGrades($gradesByStudent, $student) as $grade) {
                if (! $grade->transferred || ($term !== '' && $grade->term !== $term)) {
                    continue;
                }
                $rows[] = [
                    'id' => "{$student->districtId}-{$student->level}-{$student->code}-{$grade->term}-{$grade->subjectCode}",
                    'primary' => $grade->subjectName,
                    'secondary' => $grade->subjectCode,
                    'group' => $student->fullName().' · '.$student->code,
                    'metric' => number_format($grade->credits, 1).' หน่วยกิต',
                    'status' => 'อนุมัติ',
                ];
            }
        }

        return ['total' => count($rows), 'active' => count($rows), 'groups' => count(array_unique(array_column($rows, 'group'))), 'rows' => $rows];
    }

    /** @param array<string, mixed> $filters
     * @return array{total: int, active: int, groups: int, rows: list<array<string, string>>}
     */
    public function registeredSubjects(User $viewer, array $filters = []): array
    {
        if (($filters['view'] ?? 'subject') === 'student') {
            return $this->studentAcademicRows($viewer, $filters, 'registered-subjects');
        }

        $grouped = [];
        $studentCodes = [];
        $registrationRecords = 0;
        $students = $this->students($viewer, $this->subjectViewFilters($filters));
        $gradesByStudent = $this->repository->gradesForMany($students);
        $terms = $this->academicTerms($gradesByStudent);
        $selectedTerm = $this->selectedAcademicTerm($filters, $terms);
        $term = $selectedTerm ?? '';
        foreach ($students as $student) {
            foreach ($this->studentGrades($gradesByStudent, $student) as $grade) {
                if (($term !== '' && $grade->term !== $term)
                    || (isset($filters['subject']) && $filters['subject'] !== '' && $grade->subjectCode !== $filters['subject'])
                    || ! $this->matchesSubjectSearch($grade->subjectCode, $grade->subjectName, $filters)) {
                    continue;
                }
                $key = "{$student->level}|{$grade->term}|{$grade->subjectCode}";
                $grouped[$key] ??= ['name' => $grade->subjectName, 'code' => $grade->subjectCode, 'level' => $student->levelLabel, 'count' => 0];
                $grouped[$key]['count']++;
                $studentCodes[$student->code] = true;
                $registrationRecords++;
            }
        }
        $rows = array_values(array_map(static fn (array $row, string $key): array => [
            'id' => $key,
            'primary' => (string) $row['name'],
            'secondary' => (string) $row['code'],
            'group' => (string) $row['level'],
            'metric' => number_format((int) $row['count']).' คน',
            'status' => 'เปิดสอน',
        ], $grouped, array_keys($grouped)));

        return [
            'total' => count($rows),
            'active' => count($rows),
            'groups' => count(array_unique(array_column($rows, 'group'))),
            'summary' => [
                'subject_count' => count($rows),
                'unique_students' => count($studentCodes),
                'registered_records' => $registrationRecords,
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function gradesAboveTwo(User $viewer, array $filters = []): array
    {
        if (($filters['view'] ?? 'subject') === 'student') {
            return $this->studentAcademicRows($viewer, $filters, 'grade-threshold');
        }

        $result = $this->subjectReportRows($viewer, $filters);
        $rows = $result['rows'];
        $registered = array_sum(array_column($rows, 'registered_students'));
        $passed = array_sum(array_column($rows, 'grade_two_or_above'));

        return [
            'items' => $rows,
            'summary' => [
                'registered_records' => $registered,
                'grade_two_or_above' => $passed,
                'success_rate' => $registered > 0 ? round(($passed / $registered) * 100, 1) : 0.0,
                'unique_students' => $result['unique_students'],
                'subject_count' => count($rows),
            ],
            'terms' => $result['terms'],
            'selected_term' => $result['selected_term'],
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function examAttendance(User $viewer, array $filters = []): array
    {
        if (($filters['view'] ?? 'subject') === 'student') {
            return $this->studentAcademicRows($viewer, $filters, 'exam-attendance');
        }

        $students = $this->students($viewer, $this->subjectViewFilters($filters));
        $studentCodes = [];
        $grouped = [];
        $gradesByStudent = $this->repository->gradesForMany($students);
        $terms = $this->academicTerms($gradesByStudent);
        $selectedTerm = $this->selectedAcademicTerm($filters, $terms);
        $term = $selectedTerm ?? '';

        foreach ($students as $student) {
            foreach ($this->studentGrades($gradesByStudent, $student) as $grade) {
                if (($term !== '' && $grade->term !== $term)
                    || (isset($filters['subject']) && $filters['subject'] !== '' && $grade->subjectCode !== $filters['subject'])
                    || ! $this->matchesSubjectSearch($grade->subjectCode, $grade->subjectName, $filters)) {
                    continue;
                }

                $key = "{$grade->term}|{$grade->subjectCode}";
                $studentCodes[$student->code] = true;
                $grouped[$key] ??= [
                    'term' => $grade->term,
                    'subject' => ['code' => $grade->subjectCode, 'name' => $grade->subjectName],
                    'registered_students' => 0,
                    'attended_students' => 0,
                    'absent_students' => 0,
                ];
                $grouped[$key]['registered_students']++;
                $grouped[$key][$grade->examAttended ? 'attended_students' : 'absent_students']++;
            }
        }

        $rows = array_values(array_map(static function (array $row): array {
            $row['attendance_rate'] = $row['registered_students'] > 0
                ? round(($row['attended_students'] / $row['registered_students']) * 100, 1)
                : 0.0;

            return $row;
        }, $grouped));
        usort($rows, static fn (array $a, array $b): int => [$b['term'], $a['subject']['code']] <=> [$a['term'], $b['subject']['code']]);
        $registered = array_sum(array_column($rows, 'registered_students'));
        $attended = array_sum(array_column($rows, 'attended_students'));

        return [
            'items' => $rows,
            'summary' => [
                'unique_students' => count($studentCodes),
                'subject_count' => count($rows),
                'registered_records' => $registered,
                'attended_records' => $attended,
                'absent_records' => $registered - $attended,
                'attendance_rate' => $registered > 0 ? round(($attended / $registered) * 100, 1) : 0.0,
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return list<Student>
     */
    private function students(User $viewer, array $filters): array
    {
        // Report terms belong to academic rows. They must not be compared with the
        // student's current term or historical reports would incorrectly be empty.
        unset($filters['term']);

        return $this->directory->applyFilters($this->directory->accessibleStudents($viewer), $filters);
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, unique_students: int, terms: list<string>, selected_term: string|null}
     */
    private function subjectReportRows(User $viewer, array $filters): array
    {
        $grouped = [];
        $studentCodes = [];
        $students = $this->students($viewer, $this->subjectViewFilters($filters));
        $gradesByStudent = $this->repository->gradesForMany($students);
        $terms = $this->academicTerms($gradesByStudent);
        $selectedTerm = $this->selectedAcademicTerm($filters, $terms);
        $term = $selectedTerm ?? '';

        foreach ($students as $student) {
            foreach ($this->studentGrades($gradesByStudent, $student) as $grade) {
                if (($term !== '' && $grade->term !== $term)
                    || (isset($filters['subject']) && $filters['subject'] !== '' && $grade->subjectCode !== $filters['subject'])
                    || ! $this->matchesSubjectSearch($grade->subjectCode, $grade->subjectName, $filters)) {
                    continue;
                }

                $key = "{$grade->term}|{$grade->subjectCode}";
                $studentCodes[$student->code] = true;
                $grouped[$key] ??= [
                    'term' => $grade->term,
                    'level' => ['id' => $student->level, 'label' => $student->levelLabel],
                    'subject' => [
                        'code' => $grade->subjectCode,
                        'name' => $grade->subjectName,
                        'credits' => $grade->credits,
                        'type' => $grade->subjectType,
                    ],
                    'registered_students' => 0,
                    'grade_two_or_above' => 0,
                ];
                $grouped[$key]['registered_students']++;
                if (($grade->numericGrade() ?? -1) >= 2.0) {
                    $grouped[$key]['grade_two_or_above']++;
                }
            }
        }

        $rows = array_values(array_map(static function (array $row): array {
            $row['success_rate'] = $row['registered_students'] > 0
                ? round(($row['grade_two_or_above'] / $row['registered_students']) * 100, 1)
                : 0.0;

            return $row;
        }, $grouped));
        usort($rows, static fn (array $a, array $b): int => [$b['term'], $a['subject']['code']] <=> [$a['term'], $b['subject']['code']]);

        return [
            'rows' => $rows,
            'unique_students' => count($studentCodes),
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, summary: array<string, int|float>}
     */
    private function studentAcademicRows(User $viewer, array $filters, string $kind): array
    {
        $items = [];
        $registeredTotal = 0;
        $successfulTotal = 0;
        $studentsAttended = 0;
        $studentsNoAttendance = 0;
        $studentsAbsent = 0;
        $studentsComplete = 0;
        $studentsGradeTwoAll = 0;
        $students = $this->students($viewer, $filters);
        $gradesByStudent = $this->repository->gradesForMany($students);
        $terms = $this->academicTerms($gradesByStudent);
        $selectedTerm = $this->selectedAcademicTerm($filters, $terms);
        $term = $selectedTerm ?? '';

        foreach ($students as $student) {
            $registered = 0;
            $gradeTwoOrAbove = 0;
            $attended = 0;
            $absent = 0;

            foreach ($this->studentGrades($gradesByStudent, $student) as $grade) {
                if (($term !== '' && $grade->term !== $term)
                    || (isset($filters['subject']) && $filters['subject'] !== '' && $grade->subjectCode !== $filters['subject'])) {
                    continue;
                }

                $registered++;
                if (($grade->numericGrade() ?? -1) >= 2.0) {
                    $gradeTwoOrAbove++;
                }
                $grade->examAttended ? $attended++ : $absent++;
            }

            if ($registered === 0) {
                continue;
            }

            $successful = match ($kind) {
                'grade-threshold' => $gradeTwoOrAbove,
                'exam-attendance' => $attended,
                default => $registered,
            };
            $registeredTotal += $registered;
            $successfulTotal += $successful;
            if ($attended > 0) {
                $studentsAttended++;
            } else {
                $studentsNoAttendance++;
            }
            if ($absent > 0) {
                $studentsAbsent++;
            } else {
                $studentsComplete++;
            }
            if ($gradeTwoOrAbove === $registered) {
                $studentsGradeTwoAll++;
            }
            $items[] = [
                'student' => [
                    'code' => $student->code,
                    'full_name' => $student->fullName(),
                    'level' => ['id' => $student->level, 'label' => $student->levelLabel],
                    'group' => ['code' => $student->groupCode, 'name' => $student->groupName],
                ],
                'term' => $term,
                'registered_subjects' => $registered,
                'grade_two_or_above' => $gradeTwoOrAbove,
                'attended_subjects' => $attended,
                'absent_subjects' => $absent,
                'success_rate' => round(($successful / $registered) * 100, 1),
            ];
        }

        usort($items, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['student']['full_name'],
            (string) $right['student']['full_name'],
        ));

        return [
            'items' => $items,
            'summary' => [
                'unique_students' => count($items),
                'registered_records' => $registeredTotal,
                'successful_records' => $successfulTotal,
                'success_rate' => $registeredTotal > 0 ? round(($successfulTotal / $registeredTotal) * 100, 1) : 0.0,
                'students_attended' => $studentsAttended,
                'students_no_attendance' => $studentsNoAttendance,
                'students_absent' => $studentsAbsent,
                'students_complete' => $studentsComplete,
                'students_grade_two_all' => $studentsGradeTwoAll,
            ],
            'terms' => $terms,
            'selected_term' => $selectedTerm,
        ];
    }

    /**
     * @param  array<string, list<Grade>>  $gradesByStudent
     * @return list<string>
     */
    private function academicTerms(array $gradesByStudent): array
    {
        $terms = [];
        foreach ($gradesByStudent as $grades) {
            foreach ($grades as $grade) {
                $term = AcademicTerm::normalize($grade->term);
                if ($term !== null) {
                    $terms[$term] = true;
                }
            }
        }

        $terms = array_keys($terms);
        usort($terms, static fn (string $left, string $right): int => AcademicTerm::compare($right, $left));

        return $terms;
    }

    /** @param array<string, mixed> $filters @param list<string> $terms */
    private function selectedAcademicTerm(array $filters, array $terms): ?string
    {
        return AcademicTerm::normalize((string) ($filters['term'] ?? '')) ?? ($terms[0] ?? null);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function subjectViewFilters(array $filters): array
    {
        unset($filters['search']);

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    private function matchesSubjectSearch(string $code, string $name, array $filters): bool
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $search === '' || str_contains(mb_strtolower($code.' '.$name), $search);
    }

    /**
     * @param  array<string, list<Grade>>  $gradesByStudent
     * @return list<Grade>
     */
    private function studentGrades(array $gradesByStudent, Student $student): array
    {
        return $gradesByStudent["{$student->districtId}|{$student->level}|{$student->code}"] ?? [];
    }

    /**
     * @param  list<Student>  $students
     * @param  callable(Student): string  $keyResolver
     * @return array<string, int>
     */
    private function countBy(array $students, callable $keyResolver): array
    {
        $counts = [];
        foreach ($students as $student) {
            $key = $keyResolver($student);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
