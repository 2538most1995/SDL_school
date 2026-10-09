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

    public function test_it_skips_unverifiable_totals_and_transfers(): void
    {
        $noTotal = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [],
            coursework: null,
            finalExam: null,
            total: null,
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

        $this->assertSame('not_checkable', $noTotal['status']);
        $this->assertSame('not_checkable', $transfer['status']);
    }

    public function test_it_validates_total_score_against_grade_including_non_numeric_grades(): void
    {
        // 85 -> grade 4 (matches)
        $audit85 = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [25, 20],
            coursework: 45,
            finalExam: 40,
            total: 85,
            gradeValue: '4',
        ));
        $this->assertSame('correct', $audit85['status']);
        $this->assertSame([], $audit85['issues']);

        // 85 -> grade 3.5 (mismatch)
        $audit85Wrong = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [25, 20],
            coursework: 45,
            finalExam: 40,
            total: 85,
            gradeValue: '3.5',
        ));
        $this->assertSame('incorrect', $audit85Wrong['status']);
        $this->assertSame('grade', $audit85Wrong['issues'][0]['code']);
        $this->assertSame(4.0, $audit85Wrong['issues'][0]['expected']);
        $this->assertSame(3.5, $audit85Wrong['issues'][0]['actual']);

        // ไม่มีคะแนนปลายภาค (finalExam: null) ขึ้นเกรด 'ข' ถือว่าถูกต้องตามระเบียบ
        $auditAbsenceValid = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [10, 10, 10, 10, 10],
            coursework: 50,
            finalExam: null,
            total: 50,
            gradeValue: 'ข',
        ));
        $this->assertSame('correct', $auditAbsenceValid['status']);
        $this->assertSame([], $auditAbsenceValid['issues']);

        // มีคะแนนปลายภาค (finalExam: 12) แต่ได้เกรด 'ข' ถือว่าผิดปกติ
        $auditAbsenceWithFinal = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [],
            coursework: 47,
            finalExam: 12,
            total: 47,
            gradeValue: 'ข',
        ));
        $this->assertSame('incorrect', $auditAbsenceWithFinal['status']);
        $issueCodes = array_column($auditAbsenceWithFinal['issues'], 'code');
        $this->assertContains('grade', $issueCodes);

        // Fallback total calculation from coursework + final when total is null
        $auditFallbackTotal = ImportedScoreCalculationAudit::inspect($this->grade(
            assessments: [],
            coursework: 50,
            finalExam: 35,
            total: null,
            gradeValue: '4',
        ));
        $this->assertSame('correct', $auditFallbackTotal['status']);
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
