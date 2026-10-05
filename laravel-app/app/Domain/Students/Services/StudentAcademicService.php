<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Domain\Students\Support\ImportedScoreCalculationAudit;
use App\Models\User;

final readonly class StudentAcademicService
{
    public function __construct(
        private StudentRepository $repository,
        private StudentDirectoryService $directory,
    ) {}

    /** @return array{student: Student, items: list<Grade>, summary: array<string, mixed>}|null */
    public function grades(User $viewer, string $code, ?string $term = null, mixed $level = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code, $level);

        if ($student === null) {
            return null;
        }

        $items = array_values(array_filter(
            $this->repository->gradesFor($student),
            static fn (Grade $grade): bool => $term === null || $grade->term === $term,
        ));
        usort($items, static fn (Grade $a, Grade $b): int => [$b->term, $a->subjectCode] <=> [$a->term, $b->subjectCode]);
        $summary = $this->summarizeGrades($items);
        $gradesByTerm = [];
        foreach ($items as $grade) {
            $gradesByTerm[$grade->term][] = $grade;
        }
        $termSummaries = [];
        foreach ($gradesByTerm as $academicTerm => $termGrades) {
            $termSummary = $this->summarizeGrades($termGrades);
            $termSummaries[] = [
                'term' => $academicTerm,
                'gpa' => $termSummary['gpax'],
                'earned_credits' => $termSummary['earned_credits'],
                'graded_credits' => $termSummary['graded_credits'],
                'registered_subjects' => $termSummary['registered_subjects'],
                'passed_subjects' => $termSummary['passed_subjects'],
            ];
        }
        usort($termSummaries, static function (array $left, array $right): int {
            $leftTerm = AcademicTerm::normalize((string) $left['term']);
            $rightTerm = AcademicTerm::normalize((string) $right['term']);

            return $leftTerm !== null && $rightTerm !== null
                ? AcademicTerm::compare($rightTerm, $leftTerm)
                : strcmp((string) $right['term'], (string) $left['term']);
        });
        $summary['term_summaries'] = $termSummaries;

        return [
            'student' => $student,
            'items' => $items,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<Grade>  $items
     * @return array{gpax: ?float, earned_credits: float, compulsory_credits: float, elective_credits: float, graded_credits: float, registered_subjects: int, passed_subjects: int}
     */
    private function summarizeGrades(array $items): array
    {
        $weightedPoints = 0.0;
        $gradedCredits = 0.0;
        $earnedCredits = 0.0;
        $compulsoryCredits = 0.0;
        $electiveCredits = 0.0;
        $passedSubjects = 0;

        foreach ($items as $grade) {
            $numeric = $grade->numericGrade();
            // Preserve the established legacy GPA rule: failed numeric grades
            // do not contribute points or denominator credits.
            if ($numeric !== null && $numeric >= 1.0) {
                $weightedPoints += $numeric * $grade->credits;
                $gradedCredits += $grade->credits;
            }
            if (! $grade->isPassed()) {
                continue;
            }

            $passedSubjects++;
            $earnedCredits += $grade->credits;
            if ($grade->subjectType === 'compulsory') {
                $compulsoryCredits += $grade->credits;
            } elseif ($grade->subjectType === 'elective') {
                $electiveCredits += $grade->credits;
            }
        }

        return [
            'gpax' => $gradedCredits > 0 ? round($weightedPoints / $gradedCredits, 2) : null,
            'earned_credits' => round($earnedCredits, 2),
            'compulsory_credits' => round($compulsoryCredits, 2),
            'elective_credits' => round($electiveCredits, 2),
            'graded_credits' => round($gradedCredits, 2),
            'registered_subjects' => count($items),
            'passed_subjects' => $passedSubjects,
        ];
    }

    /**
     * Return imported ITW51 score details for the staff score grid. Student and
     * grade reads retain the repository's latest-batch rule and directory scope.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function importedScores(User $viewer, array $filters = []): array
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);

        $accessible = $this->directory->accessibleStudents($viewer);
        $levels = [];
        $groups = [];
        foreach ($accessible as $student) {
            $levels[$student->level] = $student->levelLabel;
            $groupCode = trim($student->groupCode);
            if ($groupCode !== '') {
                $groups[$groupCode] = trim($student->groupName) ?: $groupCode;
            }
        }

        $students = array_values(array_filter($accessible, static function (Student $student) use ($filters): bool {
            if (isset($filters['level']) && (int) $filters['level'] !== $student->level) {
                return false;
            }
            $group = trim((string) ($filters['group'] ?? ''));
            if ($group !== '' && ! in_array($group, [$student->groupCode, $student->groupName], true)) {
                return false;
            }
            $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

            return $search === '' || str_contains(mb_strtolower($student->code.' '.$student->fullName()), $search);
        }));

        $gradesByStudent = $this->repository->gradesForMany($students);
        $terms = [];
        foreach ($gradesByStudent as $grades) {
            foreach ($grades as $grade) {
                $normalized = AcademicTerm::normalize($grade->term);
                if ($normalized !== null) {
                    $terms[$normalized] = true;
                }
            }
        }
        $termOptions = array_keys($terms);
        usort($termOptions, static fn (string $left, string $right): int => AcademicTerm::compare($right, $left));
        $requestedTerm = trim((string) ($filters['term'] ?? ''));
        $selectedTerm = match (true) {
            $requestedTerm === 'all' => null,
            $requestedTerm !== '' => AcademicTerm::normalize($requestedTerm),
            default => $termOptions[0] ?? null,
        };
        $subjectCode = trim((string) ($filters['subject_code'] ?? ''));
        $calculationStatus = trim((string) ($filters['calculation_status'] ?? ''));
        $subjects = [];
        $rows = [];
        $calculationAudit = [
            'total_rows' => 0,
            'checked_rows' => 0,
            'incorrect_rows' => 0,
            'not_checkable_rows' => 0,
        ];

        foreach ($students as $student) {
            $studentKey = "{$student->districtId}|{$student->level}|{$student->code}";
            foreach ($gradesByStudent[$studentKey] ?? [] as $grade) {
                if ($selectedTerm !== null && AcademicTerm::normalize($grade->term) !== $selectedTerm) {
                    continue;
                }

                $subjects[$student->level.'|'.$grade->subjectCode] = [
                    'code' => $grade->subjectCode,
                    'name' => $grade->subjectName,
                    'level' => $student->level,
                ];
                if ($subjectCode !== '' && $grade->subjectCode !== $subjectCode) {
                    continue;
                }

                $assessmentScores = array_slice(array_pad($grade->assessmentScores, 9, null), 0, 9);
                $audit = ImportedScoreCalculationAudit::inspect($grade);
                $calculationAudit['total_rows']++;
                if ($audit['status'] === 'not_checkable') {
                    $calculationAudit['not_checkable_rows']++;
                } else {
                    $calculationAudit['checked_rows']++;
                }
                if ($audit['status'] === 'incorrect') {
                    $calculationAudit['incorrect_rows']++;
                }
                if ($calculationStatus === 'incorrect' && $audit['status'] !== 'incorrect') {
                    continue;
                }

                $rows[] = [
                    'student_code' => $student->code,
                    'full_name' => $student->fullName(),
                    'group_code' => $student->groupCode,
                    'group_name' => $student->groupName,
                    'level' => $student->level,
                    'subject_code' => $grade->subjectCode,
                    'subject_name' => $grade->subjectName,
                    'learning_method' => $grade->learningMethod ?? '-',
                    'assessment_scores' => $assessmentScores,
                    'midterm_score' => $grade->courseworkScore,
                    'coursework_score' => $grade->courseworkScore,
                    'final_exam_score' => $grade->finalExamScore,
                    'total_score' => $grade->totalScore,
                    'grade' => $grade->grade,
                    'calculation_audit' => $audit,
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => [
            $left['student_code'], $left['subject_code'],
        ] <=> [
            $right['student_code'], $right['subject_code'],
        ]);
        $subjectOptions = array_values($subjects);
        usort($subjectOptions, static fn (array $left, array $right): int => [
            $left['level'], $left['code'],
        ] <=> [
            $right['level'], $right['code'],
        ]);
        ksort($levels);
        ksort($groups, SORT_NATURAL);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(1000, max(1, (int) ($filters['per_page'] ?? 250)));
        $total = count($rows);
        $pagedRows = array_values(array_slice($rows, ($page - 1) * $perPage, $perPage));

        return [
            'terms' => $termOptions,
            'selected_term' => $selectedTerm,
            'levels' => array_map(
                static fn (int $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($levels),
                array_values($levels),
            ),
            'groups' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($groups),
                array_values($groups),
            ),
            'subjects' => $subjectOptions,
            'score_labels' => [
                'คะแนนบันทึกการเรียนรู้',
                'คะแนนบันทึกการฝึกทักษะ',
                'คะแนนรายงาน/รายงานเชิงปฏิบัติการ',
                'คะแนนแบบฝึกหัด',
                'คะแนนแต้มสะสมงาน',
                'คะแนนผลงาน/ชิ้นงาน',
                'คะแนนโครงงาน',
                'คะแนนทดสอบย่อย',
                'คะแนนอื่นๆ',
            ],
            'calculation_audit' => $calculationAudit,
            'rows' => $pagedRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array{student: Student, items: list<KpchActivity>, summary: array<string, mixed>}|null */
    public function kpch(User $viewer, string $code, ?string $term = null, mixed $level = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code, $level);

        if ($student === null) {
            return null;
        }

        $items = array_values(array_filter(
            $this->repository->kpchFor($student),
            static fn (KpchActivity $activity): bool => $term === null || $activity->term === $term,
        ));
        $hours = array_sum(array_map(static fn (KpchActivity $activity): float => $activity->hours, $items));

        return [
            'student' => $student,
            'items' => $items,
            'summary' => [
                'total_hours' => $hours,
                'target_hours' => 200,
                'progress_percent' => round(min(100, ($hours / 200) * 100), 1),
                'remaining_hours' => max(0, round(200 - $hours, 1)),
                'activity_count' => count($items),
            ],
        ];
    }

    /** @return array{student: Student, items: list<MoralAssessment>, summary: array<string, mixed>}|null */
    public function moral(User $viewer, string $code, ?string $term = null, mixed $level = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code, $level);

        if ($student === null) {
            return null;
        }

        $items = array_values(array_filter(
            $this->repository->moralFor($student),
            static fn (MoralAssessment $assessment): bool => $term === null || $assessment->term === $term,
        ));
        $latest = $items[0] ?? null;

        return [
            'student' => $student,
            'items' => $items,
            'summary' => [
                'latest_term' => $latest?->term,
                'latest_result' => $latest?->result,
                'latest_score' => $latest?->score,
                'latest_percent' => $latest !== null && $latest->maximumScore > 0
                    ? round(($latest->score / $latest->maximumScore) * 100, 1)
                    : null,
            ],
        ];
    }

    /** @return array{student: Student, items: list<RegisteredSubject>, summary: array<string, mixed>}|null */
    public function subjects(User $viewer, string $code, ?string $term = null, mixed $level = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code, $level);

        if ($student === null) {
            return null;
        }

        $items = array_values(array_filter(
            $this->repository->subjectsFor($student),
            static fn (RegisteredSubject $subject): bool => $term === null || $subject->term === $term,
        ));

        return [
            'student' => $student,
            'items' => $items,
            'summary' => [
                'subject_count' => count($items),
                'total_credits' => array_sum(array_map(static fn (RegisteredSubject $subject): float => $subject->credits, $items)),
                'transferred_subjects' => count(array_filter($items, static fn (RegisteredSubject $subject): bool => $subject->transferred)),
                'passed_subjects' => count(array_filter($items, static fn (RegisteredSubject $subject): bool => $subject->registrationStatus === 'passed')),
            ],
        ];
    }

    /**
     * Build the read-only subject/group roster used by trusted OMR clients.
     * Academic rows are loaded in one repository batch to avoid an API call or
     * database query per student.
     *
     * @return array{subjects: list<array<string, mixed>>, groups: array<string, list<array<string, mixed>>>, rosters: array<string, array<string, list<array<string, mixed>>>>}
     */
    public function registrationCatalog(User $viewer, ?string $term = null): array
    {
        $students = $this->directory->accessibleStudents($viewer);
        $gradesByStudent = $this->repository->gradesForMany($students);
        $subjects = [];
        $groups = [];
        $rosters = [];

        foreach ($students as $student) {
            $studentKey = "{$student->districtId}|{$student->level}|{$student->code}";
            foreach ($gradesByStudent[$studentKey] ?? [] as $grade) {
                if ($term !== null && $grade->term !== $term) {
                    continue;
                }

                $subjectCode = trim($grade->subjectCode);
                $groupCode = trim($student->groupCode);
                if ($subjectCode === '' || $groupCode === '') {
                    continue;
                }

                $subjects[$subjectCode] ??= [
                    'code' => $subjectCode,
                    'name' => trim($grade->subjectName) ?: $subjectCode,
                    '_students' => [],
                ];
                $subjects[$subjectCode]['_students'][$studentKey] = true;

                $groups[$subjectCode][$groupCode] ??= [
                    'id' => $groupCode,
                    'code' => $groupCode,
                    'name' => trim($student->groupName) ?: $groupCode,
                    '_students' => [],
                ];
                $groups[$subjectCode][$groupCode]['_students'][$studentKey] = true;

                $rosters[$subjectCode][$groupCode][$studentKey] = [
                    'code' => $student->code,
                    'full_name' => $student->fullName(),
                ];
            }
        }

        $subjectRows = [];
        foreach ($subjects as $subject) {
            $studentCount = count($subject['_students']);
            unset($subject['_students']);
            $subject['student_count'] = $studentCount;
            $subjectRows[] = $subject;
        }
        usort($subjectRows, static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code']));

        $groupRows = [];
        foreach ($groups as $subjectCode => $subjectGroups) {
            foreach ($subjectGroups as $group) {
                $studentCount = count($group['_students']);
                unset($group['_students']);
                $group['student_count'] = $studentCount;
                $groupRows[$subjectCode][] = $group;
            }
            usort($groupRows[$subjectCode], static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code']));
        }

        $rosterRows = [];
        foreach ($rosters as $subjectCode => $subjectGroups) {
            foreach ($subjectGroups as $groupCode => $studentRows) {
                $rosterRows[$subjectCode][$groupCode] = array_values($studentRows);
                usort(
                    $rosterRows[$subjectCode][$groupCode],
                    static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code']),
                );
            }
        }

        return ['subjects' => $subjectRows, 'groups' => $groupRows, 'rosters' => $rosterRows];
    }
}
