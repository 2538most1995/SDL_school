<?php

namespace App\Domain\Students\Support;

use Carbon\CarbonImmutable;

final class StudentAge
{
    public static function fromBirthDate(?string $birthDate, ?CarbonImmutable $today = null): ?int
    {
        $value = trim((string) $birthDate);
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];
        $gregorianYear = $year >= 2400 ? $year - 543 : $year;

        if (! checkdate($month, $day, $gregorianYear)) {
            return null;
        }

        $today ??= CarbonImmutable::today((string) config('app.timezone', 'Asia/Bangkok'));
        if ($gregorianYear > $today->year) {
            return null;
        }

        $age = $today->year - $gregorianYear;
        if ($today->month < $month || ($today->month === $month && $today->day < $day)) {
            $age--;
        }

        return $age >= 0 && $age <= 120 ? $age : null;
    }
}
