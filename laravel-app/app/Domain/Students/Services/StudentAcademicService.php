<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\User;

final readonly class StudentAcademicService
{
    public function __construct(
        private StudentRepository $repository,
        private StudentDirectoryService $directory,
    ) {}

    /** @return array{student: Student, items: list<Grade>, summary: array<string, mixed>}|null */
    public function grades(User $viewer, string $code, ?string $term = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code);

        if ($student === null) {
            return null;
        }

        $items = array_values(array_filter(
            $this->repository->gradesFor($student),
            static fn (Grade $grade): bool => $term === null || $grade->term === $term,
        ));
        usort($items, static fn (Grade $a, Grade $b): int => [$b->term, $a->subjectCode] <=> [$a->term, $b->subjectCode]);
        $weightedPoints = 0.0;
        $gradedCredits = 0.0;
        $earnedCredits = 0.0;
        $compulsoryCredits = 0.0;
        $electiveCredits = 0.0;

        foreach ($items as $grade) {
            $numeric = $grade->numericGrade();
            // Preserve the legacy GPAX rule: failed numeric grades (< 1) do not
            // contribute either points or denominator credits.
            if ($numeric !== null && $numeric >= 1.0) {
                $weightedPoints += $numeric * $grade->credits;
                $gradedCredits += $grade->credits;
            }
            if ($grade->isPassed()) {
                $earnedCredits += $grade->credits;
                if ($grade->subjectType === 'compulsory') {
                    $compulsoryCredits += $grade->credits;
                } elseif ($grade->subjectType === 'elective') {
                    $electiveCredits += $grade->credits;
                }
            }
        }

        return [
            'student' => $student,
            'items' => $items,
            'summary' => [
                'gpax' => $gradedCredits > 0 ? round($weightedPoints / $gradedCredits, 2) : null,
                'earned_credits' => $earnedCredits,
                'compulsory_credits' => $compulsoryCredits,
                'elective_credits' => $electiveCredits,
                'graded_credits' => $gradedCredits,
                'registered_subjects' => count($items),
                'passed_subjects' => count(array_filter($items, static fn (Grade $grade): bool => $grade->isPassed())),
            ],
        ];
    }

    /** @return array{student: Student, items: list<KpchActivity>, summary: array<string, mixed>}|null */
    public function kpch(User $viewer, string $code, ?string $term = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code);

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
    public function moral(User $viewer, string $code, ?string $term = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code);

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
    public function subjects(User $viewer, string $code, ?string $term = null): ?array
    {
        $student = $this->directory->findAccessible($viewer, $code);

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
