<?php

namespace Tests\Unit;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Support\StudentAge;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StudentAgeTest extends TestCase
{
    public function test_age_uses_the_real_birthday_in_the_current_year(): void
    {
        $beforeBirthday = CarbonImmutable::create(2026, 7, 20, 0, 0, 0, 'Asia/Bangkok');
        $onBirthday = CarbonImmutable::create(2026, 9, 28, 0, 0, 0, 'Asia/Bangkok');

        $this->assertSame(17, StudentAge::fromBirthDate('28/09/2551', $beforeBirthday));
        $this->assertSame(18, StudentAge::fromBirthDate('28/09/2551', $onBirthday));
        $this->assertSame(17, StudentAge::fromBirthDate('28/09/2008', $beforeBirthday));
    }

    public function test_student_payload_replaces_the_imported_age_with_the_current_age(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 7, 20, 0, 0, 0, 'Asia/Bangkok'));

        try {
            $student = new Student(
                code: 'TEST-001',
                districtId: 1,
                districtName: 'อำเภอเสนา',
                prefix: 'เด็กหญิง',
                firstName: 'ทดสอบ',
                lastName: 'ระบบ',
                level: 2,
                levelLabel: 'มัธยมศึกษาตอนต้น',
                groupCode: 'TEST-GROUP',
                groupName: 'กลุ่มทดสอบ',
                enrollmentTerm: '1/2569',
                currentTerm: '1/2569',
                status: 'studying',
                statusLabel: 'กำลังศึกษา',
                gpax: 0,
                creditsEarned: 0,
                creditsRequired: 56,
                kpchHours: 0,
                moralResult: 'ยังไม่มีผลประเมิน',
                demographics: ['birth_date' => '28/09/2551', 'age' => 16],
            );

            $this->assertSame(17, $student->toSummaryArray()['demographics']['age']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[DataProvider('invalidBirthDates')]
    public function test_invalid_or_incomplete_birth_dates_do_not_return_a_stale_age(?string $birthDate): void
    {
        $today = CarbonImmutable::create(2026, 7, 20, 0, 0, 0, 'Asia/Bangkok');

        $this->assertNull(StudentAge::fromBirthDate($birthDate, $today));
    }

    /** @return array<string, array{?string}> */
    public static function invalidBirthDates(): array
    {
        return [
            'missing' => [null],
            'partial' => ['/09/2551'],
            'invalid day' => ['31/02/2551'],
            'future' => ['01/01/2570'],
            'implausible' => ['01/01/2440'],
        ];
    }
}
