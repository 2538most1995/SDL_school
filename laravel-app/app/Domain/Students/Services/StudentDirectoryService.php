<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\StudentAccessScope;
use App\Models\User;

final readonly class StudentDirectoryService
{
    public function __construct(private StudentRepository $repository) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<Student>, meta: array<string, mixed>}
     */
    public function paginate(User $viewer, array $filters): array
    {
        $allAccessible = $this->accessibleStudents($viewer);
        $filtered = $this->applyFilters($allAccessible, $filters);
        $sort = (string) ($filters['sort'] ?? 'name');
        $direction = (string) ($filters['direction'] ?? 'asc');

        usort($filtered, function (Student $left, Student $right) use ($sort, $direction): int {
            $comparison = match ($sort) {
                'code' => $left->code <=> $right->code,
                'gpax' => $left->gpax <=> $right->gpax,
                'credits' => $left->creditsEarned <=> $right->creditsEarned,
                'kpch_hours' => $left->kpchHours <=> $right->kpchHours,
                default => strnatcasecmp($left->fullName(), $right->fullName()),
            };

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(1000, max(1, (int) ($filters['per_page'] ?? 25)));
        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'items' => array_values(array_slice($filtered, ($page - 1) * $perPage, $perPage)),
            'meta' => [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                    'to' => $total === 0 ? null : min($page * $perPage, $total),
                ],
                'filter_options' => $this->filterOptions($allAccessible),
                'summary' => $this->summary($filtered),
                'applied_filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
        ];
    }

    public function findAccessible(User $viewer, string $code): ?Student
    {
        $matches = array_values(array_filter(
            $this->accessibleStudents($viewer),
            static fn (Student $student): bool => hash_equals($student->code, trim($code)),
        ));

        // A student code is not globally unique across districts or levels. Refuse
        // an ambiguous lookup until the route carries a canonical opaque identity.
        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /** @return list<Student> */
    public function accessibleStudents(User $viewer): array
    {
        $scope = StudentAccessScope::forUser($viewer);
        $districtId = $viewer->relationLoaded('selectedDistrictContext')
            ? (int) $viewer->getRelation('selectedDistrictContext')
            : ($viewer->district_id !== null ? (int) $viewer->district_id : null);

        if ($districtId === null || $districtId <= 0) {
            return [];
        }

        $students = array_values(array_filter(
            $this->repository->students([$districtId]),
            static fn (Student $student): bool => $scope->allows($student),
        ));

        return $this->enrichWithSocialProfiles($districtId, $students);
    }

    /**
     * @param  list<Student>  $students
     * @return list<Student>
     */
    private function enrichWithSocialProfiles(int $districtId, array $students): array
    {
        if ($students === []) {
            return [];
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('student_social_profiles')) {
                return $students;
            }

            $codes = array_values(array_unique(array_map(static fn (Student $s): string => $s->code, $students)));
            $socialRows = \Illuminate\Support\Facades\DB::table('student_social_profiles')
                ->where('district_id', $districtId)
                ->whereIn('student_code', $codes)
                ->get(['student_code', 'facebook_url', 'line_id']);

            if ($socialRows->isEmpty()) {
                return $students;
            }

            $socialMap = [];
            foreach ($socialRows as $row) {
                $socialMap[(string) $row->student_code] = $row;
            }

            return array_map(static function (Student $student) use ($socialMap): Student {
                $social = $socialMap[(string) $student->code] ?? null;
                if ($social === null) {
                    return $student;
                }

                return new Student(
                    code: $student->code,
                    districtId: $student->districtId,
                    districtName: $student->districtName,
                    prefix: $student->prefix,
                    firstName: $student->firstName,
                    lastName: $student->lastName,
                    level: $student->level,
                    levelLabel: $student->levelLabel,
                    groupCode: $student->groupCode,
                    groupName: $student->groupName,
                    enrollmentTerm: $student->enrollmentTerm,
                    currentTerm: $student->currentTerm,
                    status: $student->status,
                    statusLabel: $student->statusLabel,
                    gpax: $student->gpax,
                    creditsEarned: $student->creditsEarned,
                    creditsRequired: $student->creditsRequired,
                    kpchHours: $student->kpchHours,
                    moralResult: $student->moralResult,
                    contact: $student->contact,
                    guardian: $student->guardian,
                    demographics: $student->demographics,
                    creditsCurrent: $student->creditsCurrent,
                    compulsoryCreditsEarned: $student->compulsoryCreditsEarned,
                    compulsoryCreditsRequired: $student->compulsoryCreditsRequired,
                    electiveCreditsEarned: $student->electiveCreditsEarned,
                    electiveCreditsRequired: $student->electiveCreditsRequired,
                    dataClassification: $student->dataClassification,
                    citizenId: $student->citizenId,
                    phone: $student->phone,
                    registeredAddress: $student->registeredAddress,
                    currentAddress: $student->currentAddress,
                    facebookUrl: $social->facebook_url ?: $student->facebookUrl,
                    lineId: $social->line_id ?: $student->lineId,
                );
            }, $students);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * @param  list<Student>  $students
     * @param  array<string, mixed>  $filters
     * @return list<Student>
     */
    public function applyFilters(array $students, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return array_values(array_filter($students, static function (Student $student) use ($filters, $search): bool {
            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $student->code,
                    $student->fullName(),
                    $student->groupCode,
                    $student->groupName,
                    $student->citizenId ?? '',
                ]));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            if (isset($filters['district_id']) && (int) $filters['district_id'] !== $student->districtId) {
                return false;
            }

            if (isset($filters['level']) && (int) $filters['level'] !== $student->level) {
                return false;
            }

            if (isset($filters['group']) && $filters['group'] !== ''
                && ! in_array((string) $filters['group'], [$student->groupCode, $student->groupName], true)) {
                return false;
            }

            return ! isset($filters['term']) || $filters['term'] === '' || $filters['term'] === $student->currentTerm;
        }));
    }

    /**
     * @param  list<Student>  $students
     * @return array<string, list<array<string, mixed>>>
     */
    private function filterOptions(array $students): array
    {
        $levels = [];
        $groups = [];
        $terms = [];
        $districts = [];

        foreach ($students as $student) {
            $levels[$student->level] = ['value' => $student->level, 'label' => $student->levelLabel];
            $groupName = trim($student->groupName) !== '' ? trim($student->groupName) : trim($student->groupCode);
            if ($groupName !== '') {
                $groups[$groupName] = ['value' => $groupName, 'label' => $groupName];
            }
            $terms[$student->currentTerm] = ['value' => $student->currentTerm, 'label' => "ภาคเรียน {$student->currentTerm}"];
            $districts[$student->districtId] = ['value' => $student->districtId, 'label' => $student->districtName];
        }

        ksort($levels);
        ksort($groups, SORT_NATURAL);
        krsort($terms, SORT_NATURAL);
        ksort($districts);

        return [
            'levels' => array_values($levels),
            'groups' => array_values($groups),
            'terms' => array_values($terms),
            'districts' => array_values($districts),
        ];
    }

    /**
     * @param  list<Student>  $students
     * @return array<string, int|float|null>
     */
    private function summary(array $students): array
    {
        $genderCounts = ['ชาย' => 0, 'หญิง' => 0];
        $groups = [];
        $levels = [];
        $gpaxTotal = 0.0;
        $gpaxCount = 0;

        foreach ($students as $student) {
            $gender = (string) ($student->demographics['gender'] ?? '');
            if (array_key_exists($gender, $genderCounts)) {
                $genderCounts[$gender]++;
            }
            $groups[$student->groupName ?: $student->groupCode] = true;
            $levels[$student->level] = true;
            if ($student->gpax > 0) {
                $gpaxTotal += $student->gpax;
                $gpaxCount++;
            }
        }

        return [
            'total' => count($students),
            'male' => $genderCounts['ชาย'],
            'female' => $genderCounts['หญิง'],
            'groups' => count($groups),
            'levels' => count($levels),
            'average_gpax' => $gpaxCount > 0 ? round($gpaxTotal / $gpaxCount, 2) : null,
        ];
    }
}
