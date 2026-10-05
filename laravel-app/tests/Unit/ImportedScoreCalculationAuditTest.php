<?php

namespace Tests\Unit;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Support\ImportedScoreCalculationAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportedScoreCalculationAuditTest extends TestCase
{
    public function test_it_reports_each_inconsistent_itw_calculation_without_changing_the_grade(): void
    {
        $grade = $this->grade(
            assessments: [10, 10, null, 10, null, 10, 10],
            coursework: 48,
            finalExam: 30,
            total: 79,
            gradeValue: '4',
        );

        $audit = ImportedScoreCalculationAudit::inspect($grade);

        $this->assertSame('incorrect', $audit['status']);
        $this->assertSame(3, $audit['check_count']);
        $this->assertSame(['assessment_total', 'total_score', 'grade'], array_column($audit['issues'], 'code'));
        $this->assertSame('4', $grade->grade);
    }

    #[DataProvider('numericGradeProvider')]
    public function test_it_accepts_the_standard_numeric_grade_boundaries(float $total, string $gradeValue): void
    {
        $audit = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [20, 20],
            coursework: 40,
            finalExam: $total - 40,
            total: $total,
            gradeValue: $gradeValue,
        ));

        $this->assertSame('correct', $audit['status']);
        $this->assertSame([], $audit['issues']);
    }

    public function test_it_skips_unverifiable_totals_special_grades_and_transfers(): void
    {
        $special = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [],
            coursework: 50,
            finalExam: null,
            total: 50,
            gradeValue: 'มส',
        ));
        $transfer = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [],
            coursework: null,
            finalExam: null,
            total: 80,
            gradeValue: '1',
            transferred: true,
        ));

        $this->assertSame('not_checkable', $special['status']);
        $this->assertSame('not_checkable', $transfer['status']);
    }

    /** @return iterable<string, array{float, string}> */
    public static function numericGradeProvider(): iterable
    {
        yield 'grade 4' => [80, '4'];
        yield 'grade 3.5' => [75, '3.5'];
        yield 'grade 3' => [70, '3'];
        yield 'grade 2.5' => [65, '2.5'];
        yield 'grade 2' => [60, '2'];
        yield 'grade 1.5' => [55, '1.5'];
        yield 'grade 1' => [50, '1'];
        yield 'grade 0' => [49, '0'];
    }

    /** @param list<float|null> $assessments */
    private function grade(
        array $assessments,
        ?float $coursework,
        ?float $finalExam,
        ?float $total,
        ?string $gradeValue,
        bool $transferred = false,
    ): Grade {
        return new Grade(
            studentCode: '6500000001',
            subjectCode: 'ทดสอบ01',
            subjectName: 'วิชาทดสอบ',
            credits: 3,
            subjectType: 'compulsory',
            term: '1/2569',
            grade: $gradeValue,
            transferred: $transferred,
            assessmentScores: $assessments,
            courseworkScore: $coursework,
            finalExamScore: $finalExam,
            totalScore: $total,
        );
    }
}
