<?php

namespace Tests\Unit;

use App\Domain\Students\Support\GraduationOpportunity;
use PHPUnit\Framework\TestCase;

final class GraduationOpportunityTest extends TestCase
{
    public function test_student_can_be_flagged_before_registering_new_subjects(): void
    {
        $plan = GraduationOpportunity::plan(41, 44, 30, 32, 26, [
            ['code' => 'ก', 'name' => 'วิชาบังคับ ก', 'credits' => 5.0],
            ['code' => 'ข', 'name' => 'วิชาบังคับ ข', 'credits' => 3.0],
        ]);

        $this->assertNotNull($plan);
        $this->assertSame(5.0, $plan['total_credits_needed']);
        $this->assertSame('ข', $plan['compulsory_subjects'][0]['code']);
        $this->assertSame(2.0, $plan['elective_credits_needed']);
    }

    public function test_student_is_not_flagged_when_a_valid_compulsory_plan_exceeds_term_limit(): void
    {
        $this->assertNull(GraduationOpportunity::plan(40, 44, 30, 32, 5, [
            ['code' => 'ก', 'name' => 'วิชาบังคับ ก', 'credits' => 4.0],
        ]));
    }
}
