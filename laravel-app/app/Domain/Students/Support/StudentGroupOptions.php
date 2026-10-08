<?php

namespace App\Domain\Students\Support;

use App\Domain\Students\Models\Student;

final class StudentGroupOptions
{
    /**
     * Build one option per visible group name. ITW may assign a different
     * group code to the same learning centre at each education level.
     *
     * @param  list<Student>  $students
     * @return list<array{value: string, label: string}>
     */
    public static function fromStudents(array $students, ?int $level = null): array
    {
        $options = [];

        foreach ($students as $student) {
            if ($level !== null && $student->level !== $level) {
                continue;
            }

            $name = self::normalize($student->groupName);
            $code = self::normalize($student->groupCode);
            $label = $name !== '' ? $name : $code;
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            $options[$key] ??= ['value' => $label, 'label' => $label];
        }

        $options = array_values($options);
        usort($options, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));

        return $options;
    }

    public static function matches(Student $student, string $filter): bool
    {
        $filter = mb_strtolower(self::normalize($filter));
        if ($filter === '') {
            return true;
        }

        return in_array($filter, [
            mb_strtolower(self::normalize($student->groupCode)),
            mb_strtolower(self::normalize($student->groupName)),
        ], true);
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
