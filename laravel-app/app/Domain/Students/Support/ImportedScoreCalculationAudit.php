<?php

namespace App\Domain\Students\Support;

use App\Domain\Students\Models\Grade;

final class ImportedScoreCalculationAudit
{
    /**
     * Inspect calculations stored in GRADE.DBF without changing source data.
     *
     * A comparison is only made when every value required for that comparison
     * is present. Special grades and transferred subjects are deliberately not
     * converted to a numeric grade to avoid false positives.
     *
     * @return array{
     *     status: 'correct'|'incorrect'|'not_checkable',
     *     check_count: int,
     *     issues: list<array{code: string, label: string, expected: float, actual: float}>
     * }
     */
    public static function inspect(Grade $grade): array
    {
        $issues = [];
        $checkCount = 0;
        $assessmentScores = array_values(array_filter(
            $grade->assessmentScores,
            static fn (?float $score): bool => $score !== null,
        ));

        if ($assessmentScores !== [] && $grade->courseworkScore !== null) {
            $checkCount++;
            self::compare(
                $issues,
                'assessment_total',
                'ผลรวมคะแนนช่อง 1-9 ไม่ตรงกับคะแนนกลางภาค',
                array_sum($assessmentScores),
                $grade->courseworkScore,
            );
        }

        if ($grade->courseworkScore !== null && $grade->finalExamScore !== null && $grade->totalScore !== null) {
            $checkCount++;
            self::compare(
                $issues,
                'total_score',
                'คะแนนกลางภาคบวกปลายภาคไม่ตรงกับคะแนนรวม',
                $grade->courseworkScore + $grade->finalExamScore,
                $grade->totalScore,
            );
        }

        if (! $grade->transferred && $grade->totalScore !== null && $grade->grade !== null && is_numeric($grade->grade)) {
            $checkCount++;
            self::compare(
                $issues,
                'grade',
                'เกรดไม่ตรงกับคะแนนรวม',
                self::gradeForTotal($grade->totalScore),
                (float) $grade->grade,
            );
        }

        return [
            'status' => $checkCount === 0 ? 'not_checkable' : ($issues === [] ? 'correct' : 'incorrect'),
            'check_count' => $checkCount,
            'issues' => $issues,
        ];
    }

    /** @param list<array{code: string, label: string, expected: float, actual: float}> $issues */
    private static function compare(array &$issues, string $code, string $label, float $expected, float $actual): void
    {
        $expected = round($expected, 2);
        $actual = round($actual, 2);
        if (abs($expected - $actual) <= 0.01) {
            return;
        }

        $issues[] = compact('code', 'label', 'expected', 'actual');
    }

    private static function gradeForTotal(float $total): float
    {
        return match (true) {
            $total >= 80 => 4.0,
            $total >= 75 => 3.5,
            $total >= 70 => 3.0,
            $total >= 65 => 2.5,
            $total >= 60 => 2.0,
            $total >= 55 => 1.5,
            $total >= 50 => 1.0,
            default => 0.0,
        };
    }
}
