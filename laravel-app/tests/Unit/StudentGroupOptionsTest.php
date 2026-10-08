<?php

namespace Tests\Unit;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\StudentGroupOptions;
use PHPUnit\Framework\TestCase;

final class StudentGroupOptionsTest extends TestCase
{
    public function test_it_deduplicates_the_same_group_name_across_education_levels(): void
    {
        $students = [
            $this->student('6721000001', 1, '210001', 'ศกร.ระดับตำบลสามกอ'),
            $this->student('6722000001', 2, '220001', 'ศกร.ระดับตำบลสามกอ'),
            $this->student('6722000002', 2, '220002', 'ศกร.ระดับตำบลบางนมโค'),
        ];

        $this->assertSame([
            ['value' => 'ศกร.ระดับตำบลบางนมโค', 'label' => 'ศกร.ระดับตำบลบางนมโค'],
            ['value' => 'ศกร.ระดับตำบลสามกอ', 'label' => 'ศกร.ระดับตำบลสามกอ'],
        ], StudentGroupOptions::fromStudents($students));

        $this->assertSame([
            ['value' => 'ศกร.ระดับตำบลสามกอ', 'label' => 'ศกร.ระดับตำบลสามกอ'],
        ], StudentGroupOptions::fromStudents($students, 1));
    }

    public function test_it_matches_both_the_group_name_and_legacy_group_code(): void
    {
        $student = $this->student('6722000001', 2, '220001', 'ศกร.ระดับตำบลสามกอ');

        $this->assertTrue(StudentGroupOptions::matches($student, 'ศกร.ระดับตำบลสามกอ'));
        $this->assertTrue(StudentGroupOptions::matches($student, '220001'));
        $this->assertFalse(StudentGroupOptions::matches($student, 'ศกร.ระดับตำบลอื่น'));
    }

    private function student(string $code, int $level, string $groupCode, string $groupName): Student
    {
        return new Student(
            code: $code,
            districtId: 1,
            districtName: 'อำเภอเสนา',
            prefix: 'นาย',
            firstName: 'ทดสอบ',
            lastName: $code,
            level: $level,
            levelLabel: match ($level) {
                1 => 'ประถมศึกษา',
                2 => 'มัธยมศึกษาตอนต้น',
                default => 'มัธยมศึกษาตอนปลาย',
            },
            groupCode: $groupCode,
            groupName: $groupName,
            enrollmentTerm: '1/2569',
            currentTerm: '1/2569',
            status: 'studying',
            statusLabel: 'กำลังศึกษา',
            gpax: 0,
            creditsEarned: 0,
            creditsRequired: 0,
            kpchHours: 0,
            moralResult: 'ยังไม่มีผลประเมิน',
        );
    }
}
