<?php

namespace App\Domain\Students\Support;

final class ExamEligibilityStatistics
{
    public static function isDisqualifyingStatus(mixed $status): bool
    {
        return in_array(trim((string) $status), ['ม', 'มส'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $eligibleStudents
     * @return list<array<string, mixed>>
     */
    public static function byGroup(array $eligibleStudents): array
    {
        $groups = [];

        foreach ($eligibleStudents as $item) {
            $student = is_array($item['student'] ?? null) ? $item['student'] : [];
            $group = is_array($student['group'] ?? null) ? $student['group'] : [];
            $level = is_array($student['level'] ?? null) ? $student['level'] : [];
            $groupName = trim((string) ($group['name'] ?? '')) ?: trim((string) ($group['code'] ?? '')) ?: 'ไม่ระบุกลุ่ม';
            $key = mb_strtolower($groupName);

            $groups[$key] ??= [
                'group_name' => $groupName,
                'primary_students' => 0,
                'lower_secondary_students' => 0,
                'upper_secondary_students' => 0,
                'total_students' => 0,
            ];

            match ((int) ($level['id'] ?? 0)) {
                1 => $groups[$key]['primary_students']++,
                2 => $groups[$key]['lower_secondary_students']++,
                3 => $groups[$key]['upper_secondary_students']++,
                default => null,
            };
            $groups[$key]['total_students']++;
        }

        $rows = array_values($groups);
        usort($rows, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['group_name'],
            (string) $right['group_name'],
        ));

        return $rows;
    }
}
