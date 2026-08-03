<?php

namespace App\Domain\Students\Support;

final class AcademicTerm
{
    public static function normalize(?string $term): ?string
    {
        if ($term === null || trim($term) === '') {
            return null;
        }

        $value = strtr((string) preg_replace('/\s+/u', '', trim($term)), [
            '๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4',
            '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9',
        ]);

        if (preg_match('/^(\d{1,4})[\/\-](\d{1,4})$/', $value, $matches) !== 1) {
            return null;
        }

        $left = (int) $matches[1];
        $right = (int) $matches[2];

        if ($left > 4 && $right >= 1 && $right <= 4) {
            $year = self::buddhistYear($left);
            $semester = $right;
        } elseif ($right > 4 && $left >= 1 && $left <= 4) {
            $year = self::buddhistYear($right);
            $semester = $left;
        } else {
            return null;
        }

        if ($year < 2500 || $year > 2999) {
            return null;
        }

        return "{$semester}/{$year}";
    }

    public static function compare(string $left, string $right): int
    {
        [$leftSemester, $leftYear] = array_map('intval', explode('/', $left));
        [$rightSemester, $rightYear] = array_map('intval', explode('/', $right));

        return [$leftYear, $leftSemester] <=> [$rightYear, $rightSemester];
    }

    /** @param list<string|null> $terms */
    public static function latest(array $terms): ?string
    {
        $normalized = array_values(array_unique(array_filter(array_map(self::normalize(...), $terms))));

        usort($normalized, self::compare(...));

        return $normalized === [] ? null : $normalized[array_key_last($normalized)];
    }

    /** @return list<string> */
    public static function variants(string $term): array
    {
        $normalized = self::normalize($term);
        if ($normalized === null) {
            return [];
        }

        [$semester, $year] = array_map('intval', explode('/', $normalized));
        $shortYear = str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT);
        $christianYear = $year - 543;

        return array_values(array_unique([
            $normalized,
            "{$year}/{$semester}",
            "{$semester}/{$shortYear}",
            "{$shortYear}/{$semester}",
            "{$semester}/{$christianYear}",
            "{$christianYear}/{$semester}",
        ]));
    }

    private static function buddhistYear(int $year): int
    {
        if ($year < 100) {
            return 2500 + $year;
        }

        return $year >= 1900 && $year < 2400 ? $year + 543 : $year;
    }
}
