<?php

namespace App\Services\Legacy;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Domain\Students\Support\AcademicTerm;
use App\Support\ThaiAdministrativeAreaLookup;
use Illuminate\Database\DatabaseManager;

final class ExamRoomScopeService
{
    /** @var array<int, array<string, mixed>> */
    private array $scopesByDistrict = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly StudentRepository $students,
        private readonly ThaiAdministrativeAreaLookup $areaLookup,
    ) {}

    /**
     * @return array{
     *     term: string|null,
     *     student_targets: list<array{values: list<string>, education_level: int, subdistrict: string|null}>,
     *     group_targets: list<array{values: list<string>, education_level: int, subdistrict: string|null}>,
     *     subdistricts: list<string>,
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
        $districtName = $students[0]->districtName ?? (string) $this->database->connection()
            ->table('districts')->where('id', $districtId)->value('name');
        $subdistrictNames = $this->areaLookup->subdistrictsForDistrict($districtName);
        $subdistricts = [];
        $levels = [];
        $studentTargets = [];
        $groupTargets = [];
        foreach ($students as $student) {
            if (in_array($student->level, [1, 2, 3], true)) {
                $levels[$student->level] = $student->levelLabel;
            }
            $subdistrict = $this->studentSubdistrict($student, $subdistrictNames);
            if ($subdistrict !== null) {
                $subdistricts[$subdistrict] = true;
            }
            $studentTargets[] = [
                'values' => [$student->code],
                'education_level' => $student->level,
                'subdistrict' => $subdistrict,
            ];
            $groupKey = implode("\0", [$student->groupCode, $student->groupName, (string) $student->level, $subdistrict ?? '']);
            $groupTargets[$groupKey] = [
                'values' => array_values(array_filter([$student->groupCode, $student->groupName])),
                'education_level' => $student->level,
                'subdistrict' => $subdistrict,
            ];
        }
        $subdistricts = array_keys($subdistricts);
        sort($subdistricts, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($levels);

        return $this->scopesByDistrict[$districtId] = [
            'term' => $currentTerm,
            'student_targets' => $studentTargets,
            'group_targets' => array_values($groupTargets),
            'subdistricts' => $subdistricts,
            'education_levels' => array_map(
                static fn (int $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($levels),
                array_values($levels),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $districtScope
     * @return array{education_levels: list<int>, subdistricts: list<string>}
     */
    public function forRoom(object $room, array $districtScope): array
    {
        $targets = (string) $room->assignment_type === 'group_range'
            ? $districtScope['group_targets']
            : $districtScope['student_targets'];
        $levels = [];
        $subdistricts = [];
        foreach ($targets as $target) {
            $matches = array_filter($target['values'], fn (string $value): bool => $this->matchValue(
                $value,
                (string) $room->start_val,
                (string) $room->end_val,
            ));
            if ($matches === []) {
                continue;
            }
            if (in_array($target['education_level'], [1, 2, 3], true)) {
                $levels[$target['education_level']] = true;
            }
            if ($target['subdistrict'] !== null) {
                $subdistricts[$target['subdistrict']] = true;
            }
        }
        $levelValues = array_map('intval', array_keys($levels));
        sort($levelValues);
        $subdistrictValues = array_keys($subdistricts);
        sort($subdistrictValues, SORT_NATURAL | SORT_FLAG_CASE);

        return ['education_levels' => $levelValues, 'subdistricts' => $subdistrictValues];
    }

    public function rangeCapacity(string $start, string $end): ?int
    {
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

    /** @param list<string> $subdistrictNames */
    private function studentSubdistrict(Student $student, array $subdistrictNames): ?string
    {
        $groupDigits = preg_replace('/\D+/', '', $student->groupCode) ?? '';
        foreach (array_unique(array_filter([$groupDigits, substr($groupDigits, 0, 6)])) as $code) {
            $area = $this->areaLookup->resolve($code);
            if ($area !== null && in_array($area['subdistrict'], $subdistrictNames, true)) {
                return $area['subdistrict'];
            }
        }

        $groupName = preg_replace('/\s+/u', '', $student->groupName) ?? $student->groupName;
        foreach ($subdistrictNames as $subdistrict) {
            $compact = preg_replace('/\s+/u', '', $subdistrict) ?? $subdistrict;
            if (str_contains($groupName, 'ตำบล'.$compact)) {
                return $subdistrict;
            }
        }
        foreach ($subdistrictNames as $subdistrict) {
            $compact = preg_replace('/\s+/u', '', $subdistrict) ?? $subdistrict;
            if ($compact !== '' && str_contains($groupName, $compact)) {
                return $subdistrict;
            }
        }

        return null;
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
}
