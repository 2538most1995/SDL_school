<?php

namespace Tests\Unit;

use App\Domain\Students\Support\RegistrationCreditPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RegistrationCreditPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, float, float}> */
    public static function creditLimits(): iterable
    {
        yield 'primary' => [1, 14.0, 17.0];
        yield 'lower-secondary' => [2, 17.0, 20.0];
        yield 'upper-secondary' => [3, 23.0, 26.0];
    }

    #[DataProvider('creditLimits')]
    public function test_each_level_uses_regular_and_final_term_limits(int $level, float $regular, float $final): void
    {
        $requirements = match ($level) {
            1 => [36.0, 12.0],
            2 => [40.0, 16.0],
            3 => [44.0, 32.0],
        };

        $normal = RegistrationCreditPolicy::evaluate($level, 0, 0, $regular, 0);
        $this->assertFalse($normal['is_potential_graduate']);
        $this->assertFalse($normal['is_credit_complete']);
        $this->assertSame($regular, $normal['applicable_limit']);
        $this->assertFalse($normal['exceeds_limit']);

        $finalTerm = RegistrationCreditPolicy::evaluate(
            $level,
            $requirements[0] - 1,
            $requirements[1] - 1,
            1,
            $final - 1,
        );
        $this->assertTrue($finalTerm['is_potential_graduate']);
        $this->assertFalse($finalTerm['is_credit_complete']);
        $this->assertSame($final, $finalTerm['applicable_limit']);
        $this->assertFalse($finalTerm['exceeds_limit']);

        $over = RegistrationCreditPolicy::evaluate(
            $level,
            $requirements[0] - 1,
            $requirements[1] - 1,
            1,
            $final,
        );
        $this->assertTrue($over['exceeds_limit']);
        $this->assertSame(1.0, $over['excess_credits']);
    }

    public function test_total_credits_alone_do_not_grant_final_term_limit(): void
    {
        $policy = RegistrationCreditPolicy::evaluate(
            level: 1,
            compulsoryEarned: 48,
            electiveEarned: 0,
            compulsorySelected: 15,
            electiveSelected: 0,
        );

        $this->assertFalse($policy['is_potential_graduate']);
        $this->assertSame(14.0, $policy['applicable_limit']);
        $this->assertTrue($policy['exceeds_limit']);
    }

    public function test_earned_compulsory_and_elective_credits_are_complete_not_potential(): void
    {
        $policy = RegistrationCreditPolicy::evaluate(
            level: 1,
            compulsoryEarned: 36,
            electiveEarned: 12,
            compulsorySelected: 0,
            electiveSelected: 0,
        );

        $this->assertTrue($policy['is_credit_complete']);
        $this->assertFalse($policy['is_potential_graduate']);
        $this->assertTrue($policy['uses_final_term_limit']);
        $this->assertSame(17.0, $policy['applicable_limit']);
    }
}
