<?php

namespace Tests\Unit;

use App\Domain\Students\Support\ExamEligibilityStatistics;
use PHPUnit\Framework\TestCase;

final class ExamEligibilityStatisticsTest extends TestCase
{
    public function test_it_combines_the_same_group_name_across_levels(): void
    {
        $eligible = [
            $this->student('ศกร.ระดับตำบลมารวิชัย', 1),
            $this->student('ศกร.ระดับตำบลมารวิชัย', 2),
            $this->student('ศกร.ระดับตำบลมารวิชัย', 2),
            $this->student('ศกร.ระดับตำบลมารวิชัย', 3),
            $this->student('ศกร.ระดับตำบลสามกอ', 3),
        ];

        $result = ExamEligibilityStatistics::byGroup($eligible);

        $this->assertCount(2, $result);
        $marawichai = collect($result)->firstWhere('group_name', 'ศกร.ระดับตำบลมารวิชัย');
        $this->assertSame(1, $marawichai['primary_students']);
        $this->assertSame(2, $marawichai['lower_secondary_students']);
        $this->assertSame(1, $marawichai['upper_secondary_students']);
        $this->assertSame(4, $marawichai['total_students']);
    }

    /** @return array<string, mixed> */
    private function student(string $groupName, int $level): array
    {
        return [
            'student' => [
                'group' => ['code' => 'G-'.$level, 'name' => $groupName],
                'level' => ['id' => $level, 'label' => 'ระดับ '.$level],
            ],
        ];
    }
}
