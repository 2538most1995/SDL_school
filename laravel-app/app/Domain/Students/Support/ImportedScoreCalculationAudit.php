<?php

namespace App\Domain\Students\Support;

use App\Domain\Students\Models\Grade;

final class ImportedScoreCalculationAudit
{
    /**
     * Inspect calculations stored in GRADE.DBF without changing source data.
     *
     * A comparison is only made when every value required for that comparison
     * is present. An absent grade (ข) is only exempt when the final exam score
     * is actually missing; a recorded zero is still a score. Other special
     * grades and transferred subjects are not converted to a numeric grade.
     *
     * @return array{
     *     status: 'correct'|'incorrect'|'not_checkable',
     *     check_count: int,
     *     reason: 'transferred'|'special_grade'|'missing_final_exam'|'incomplete_data'|null,
     *     issues: list<array{code: string, label: string, expected: float|string, actual: float|string}>
     * }
     */
    public static function inspect(Grade $grade): array
    {
        $reason = match (true) {
            $grade->transferred => 'transferred',
            in_array(trim((string) $grade->grade), ['ม', 'มส', 'ผ', 'มผ', 'ร'], true) => 'special_grade',
            $grade->finalExamScore === null => 'missing_final_exam',
            default => null,
        };
        if ($reason !== null) {
            return [
                'status' => 'not_checkable',
                'check_count' => 0,
                'reason' => $reason,
                'issues' => [],
            ];
        }

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

        if ($grade->courseworkScore !== null && $grade->totalScore !== null) {
            $checkCount++;
            self::compare(
                $issues,
                'total_score',
                'คะแนนกลางภาคบวกปลายภาคไม่ตรงกับคะแนนรวม',
                $grade->courseworkScore + $grade->finalExamScore,
                $grade->totalScore,
            );
        }

        $totalForGrade = $grade->totalScore ?? ($grade->courseworkScore !== null ? $grade->courseworkScore + $grade->finalExamScore : null);

        if ($grade->grade !== null && trim($grade->grade) !== '') {
            $gradeValue = trim($grade->grade);
            if ($totalForGrade !== null) {
                $checkCount++;
                self::compare(
                    $issues,
                    'grade',
                    'เกรดไม่ตรงกับคะแนนรวม',
                    self::gradeForTotal($totalForGrade),
                    $gradeValue,
                );
            }
        }

        return [
            'status' => $checkCount === 0 ? 'not_checkable' : ($issues === [] ? 'correct' : 'incorrect'),
            'check_count' => $checkCount,
            'reason' => $checkCount === 0 ? 'incomplete_data' : null,
            'issues' => $issues,
        ];
    }

    /** @param list<array{code: string, label: string, expected: float|string, actual: float|string}> $issues */
    private static function compare(array &$issues, string $code, string $label, float|string $expected, float|string $actual): void
    {
        if (is_numeric($expected) && is_numeric($actual)) {
            $expectedNum = round((float) $expected, 2);
            $actualNum = round((float) $actual, 2);
            if (abs($expectedNum - $actualNum) <= 0.01) {
                return;
            }
            $expected = $expectedNum;
            $actual = $actualNum;
        } else {
            $expectedStr = is_numeric($expected) ? self::formatGradeValue((float) $expected) : trim((string) $expected);
            $actualStr = trim((string) $actual);
            if ($expectedStr === $actualStr) {
                return;
            }
            $expected = is_numeric($expected) ? self::formatGradeValue((float) $expected) : $expectedStr;
            $actual = $actualStr;
        }

        $issues[] = compact('code', 'label', 'expected', 'actual');
    }

    private static function formatGradeValue(float $grade): string
    {
        return fmod($grade, 1.0) === 0.0 ? (string) (int) $grade : (string) $grade;
    }

    public static function gradeForTotal(float $total): float
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
