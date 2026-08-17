<?php

namespace App\Services\Legacy;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;

final class ExamRoomScopeService
{
    /** @var array<int, array<string, mixed>> */
    private array $scopesByDistrict = [];

    public function __construct(private readonly StudentRepository $students) {}

    /**
     * @return array{
     *     term: string|null,
     *     student_targets: list<array{values: list<string>, education_level: int, group: string|null}>,
     *     group_targets: list<array{values: list<string>, education_level: int, group: string|null}>,
     *     student_index: array<string, array{education_levels: list<int>, groups: list<string>}>,
     *     group_index: array<string, array{education_levels: list<int>, groups: list<string>}>,
     *     groups: list<array{value: string, label: string}>,
     *     education_levels: list<array{value: int, label: string}>
     * }
     */
    public function forDistrict(int $districtId): array
    {
        if (array_key_exists($districtId, $this->scopesByDistrict)) {
            return $this->scopesByDistrict[$districtId];
        }

        $students = $this->students->students([$districtId]);
        $currentTerm = AcademicTerm::latest(array_map(
            static fn (Student $student): string => $student->currentTerm,
            $students,
        ));
        $students = $currentTerm === null ? [] : array_values(array_filter(
            $students,
            static fn (Student $student): bool => AcademicTerm::normalize($student->currentTerm) === $currentTerm,
        ));
        $groups = [];
        $levels = [];
        $studentTargets = [];
        $groupTargets = [];
        foreach ($students as $student) {
            if (in_array($student->level, [1, 2, 3], true)) {
                $levels[$student->level] = $student->levelLabel;
            }
            $group = $this->studentGroup($student);
            if ($group !== null) {
                $groups[$this->targetKey($group['value'])] ??= $group;
            }
            $studentTargets[] = [
                'values' => [$student->code],
                'education_level' => $student->level,
                'group' => $group['value'] ?? null,
            ];
            $groupKey = implode("\0", [$student->groupCode, $student->groupName, (string) $student->level]);
            $groupTargets[$groupKey] = [
                'values' => array_values(array_filter([$student->groupCode, $student->groupName])),
                'education_level' => $student->level,
                'group' => $group['value'] ?? null,
            ];
        }
        $groupOptions = array_values($groups);
        usort($groupOptions, static fn (array $left, array $right): int => strnatcasecmp(
            $left['label'].' '.$left['value'],
            $right['label'].' '.$right['value'],
        ));
        ksort($levels);

        return $this->scopesByDistrict[$districtId] = [
            'term' => $currentTerm,
            'student_targets' => $studentTargets,
            'group_targets' => array_values($groupTargets),
            'student_index' => $this->targetIndex($studentTargets),
            'group_index' => $this->targetIndex(array_values($groupTargets)),
            'groups' => $groupOptions,
            'education_levels' => array_map(
                static fn (int $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($levels),
                array_values($levels),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $districtScope
     * @return array{education_levels: list<int>, groups: list<string>}
     */
    public function forRoom(object $room, array $districtScope): array
    {
        $groupRange = (string) $room->assignment_type === 'group_range';
        $targets = $groupRange ? $districtScope['group_targets'] : $districtScope['student_targets'];
        $index = $groupRange ? $districtScope['group_index'] : $districtScope['student_index'];
        $start = trim((string) $room->start_val);
        $end = trim((string) $room->end_val);
        $effectiveEnd = $end === '' ? $start : $end;
        if (! $this->isWildcard($start) && ! $this->isWildcard($effectiveEnd)
            && $this->targetKey($start) === $this->targetKey($effectiveEnd)
            && isset($index[$this->targetKey($start)])) {
            return $index[$this->targetKey($start)];
        }

        $levels = [];
        $groups = [];
        foreach ($targets as $target) {
            $matches = array_filter($target['values'], fn (string $value): bool => $this->matchValue(
                $value,
                $start,
                $effectiveEnd,
            ));
            if ($matches === []) {
                continue;
            }
            if (in_array($target['education_level'], [1, 2, 3], true)) {
                $levels[$target['education_level']] = true;
            }
            if ($target['group'] !== null) {
                $groups[$this->targetKey($target['group'])] = $target['group'];
            }
        }
        $levelValues = array_map('intval', array_keys($levels));
        sort($levelValues);
        $groupValues = array_values($groups);
        sort($groupValues, SORT_NATURAL | SORT_FLAG_CASE);

        return ['education_levels' => $levelValues, 'groups' => $groupValues];
    }

    public function rangeCapacity(string $start, string $end): ?int
    {
        if (trim($end) === '') {
            $end = $start;
        }
        $start = ltrim(trim($start), '0') ?: '0';
        $end = ltrim(trim($end), '0') ?: '0';
        if (! ctype_digit($start) || ! ctype_digit($end)
            || strlen($start) > strlen($end)
            || (strlen($start) === strlen($end) && strcmp($start, $end) > 0)) {
            return null;
        }

        $difference = '';
        $borrow = 0;
        $startIndex = strlen($start) - 1;
        for ($endIndex = strlen($end) - 1; $endIndex >= 0; $endIndex--, $startIndex--) {
            $digit = ((int) $end[$endIndex]) - $borrow - ($startIndex >= 0 ? (int) $start[$startIndex] : 0);
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $difference = (string) $digit.$difference;
        }
        $difference = ltrim($difference, '0') ?: '0';
        $maxDifference = (string) (PHP_INT_MAX - 1);
        if (strlen($difference) > strlen($maxDifference)
            || (strlen($difference) === strlen($maxDifference) && strcmp($difference, $maxDifference) > 0)) {
            return null;
        }

        return (int) $difference + 1;
    }

    /**
     * @param  list<array{values: list<string>, education_level: int, group: string|null}>  $targets
     * @return array<string, array{education_levels: list<int>, groups: list<string>}>
     */
    private function targetIndex(array $targets): array
    {
        $index = [];
        foreach ($targets as $target) {
            foreach ($target['values'] as $value) {
                $key = $this->targetKey($value);
                if ($key === '') {
                    continue;
                }
                $index[$key] ??= ['education_levels' => [], 'groups' => []];
                if (in_array($target['education_level'], [1, 2, 3], true)) {
                    $index[$key]['education_levels'][$target['education_level']] = true;
                }
                if ($target['group'] !== null) {
                    $index[$key]['groups'][$this->targetKey($target['group'])] = $target['group'];
                }
            }
        }

        return array_map(static function (array $scope): array {
            $levels = array_map('intval', array_keys($scope['education_levels']));
            sort($levels);
            $groups = array_values($scope['groups']);
            sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

            return ['education_levels' => $levels, 'groups' => $groups];
        }, $index);
    }

    /** @return array{value: string, label: string}|null */
    private function studentGroup(Student $student): ?array
    {
        $code = trim($student->groupCode);
        $name = trim($student->groupName);
        $value = $code !== '' ? $code : $name;
        if ($value === '') {
            return null;
        }

        return ['value' => $value, 'label' => $name !== '' ? $name : $value];
    }

    private function matchValue(string $value, string $start, string $end): bool
    {
        $value = trim($value);
        $start = trim($start);
        $end = trim($end);
        if ($value === '') {
            return false;
        }
        if ($start === '' || $start === '*' || strtolower($start) === 'all'
            || $end === '' || $end === '*' || strtolower($end) === 'all') {
            return true;
        }
        if (ctype_digit($value) && ctype_digit($start) && ctype_digit($end)) {
            $normalizedValue = ltrim($value, '0') ?: '0';
            $normalizedStart = ltrim($start, '0') ?: '0';
            $normalizedEnd = ltrim($end, '0') ?: '0';
            $compareNumbers = static fn (string $left, string $right): int => strlen($left) <=> strlen($right)
                ?: strcmp($left, $right);

            return $compareNumbers($normalizedValue, $normalizedStart) >= 0
                && $compareNumbers($normalizedValue, $normalizedEnd) <= 0;
        }
        if (strcasecmp($value, $start) === 0 || strcasecmp($value, $end) === 0) {
            return true;
        }
        if (str_contains($value, $start) || str_contains($value, $end)) {
            return true;
        }

        return strnatcasecmp($value, $start) >= 0 && strnatcasecmp($value, $end) <= 0;
    }

    private function isWildcard(string $value): bool
    {
        return $value === '' || $value === '*' || strtolower($value) === 'all';
    }

    private function targetKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ctype_digit($value)
            ? 'number:'.(ltrim($value, '0') ?: '0')
            : 'text:'.mb_strtolower($value, 'UTF-8');
    }
}
