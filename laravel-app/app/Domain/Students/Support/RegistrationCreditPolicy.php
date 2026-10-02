<?php

namespace App\Domain\Students\Support;

final class RegistrationCreditPolicy
{
    /**
     * @return array{regular: float, final: float}
     */
    public static function limits(int $level): array
    {
        return match ($level) {
            1 => ['regular' => 14.0, 'final' => 17.0],
            2 => ['regular' => 17.0, 'final' => 20.0],
            3 => ['regular' => 23.0, 'final' => 26.0],
            default => ['regular' => 0.0, 'final' => 0.0],
        };
    }

    /**
     * @return array{
     *     compulsory_earned: float,
     *     elective_earned: float,
     *     compulsory_selected: float,
     *     elective_selected: float,
     *     total_selected: float,
     *     projected_compulsory: float,
     *     projected_elective: float,
     *     compulsory_required: float,
     *     elective_required: float,
     *     is_credit_complete: bool,
     *     is_potential_graduate: bool,
     *     uses_final_term_limit: bool,
     *     regular_limit: float,
     *     final_term_limit: float,
     *     applicable_limit: float,
     *     exceeds_limit: bool,
     *     excess_credits: float
     * }
     */
    public static function evaluate(
        int $level,
        float $compulsoryEarned,
        float $electiveEarned,
        float $compulsorySelected,
        float $electiveSelected,
        ?float $compulsoryRequired = null,
        ?float $electiveRequired = null,
    ): array {
        $requirements = CurriculumCatalog::creditRequirements($level);
        $limits = self::limits($level);
        $compulsoryRequired ??= $requirements['compulsory'];
        $electiveRequired ??= $requirements['elective'];

        $compulsoryEarned = self::credits($compulsoryEarned);
        $electiveEarned = self::credits($electiveEarned);
        $compulsorySelected = self::credits($compulsorySelected);
        $electiveSelected = self::credits($electiveSelected);
        $projectedCompulsory = self::credits($compulsoryEarned + $compulsorySelected);
        $projectedElective = self::credits($electiveEarned + $electiveSelected);
        $totalSelected = self::credits($compulsorySelected + $electiveSelected);
        $isCreditComplete = $compulsoryEarned >= $compulsoryRequired
            && $electiveEarned >= $electiveRequired;
        $isPotentialGraduate = ! $isCreditComplete
            && $projectedCompulsory >= $compulsoryRequired
            && $projectedElective >= $electiveRequired;
        $usesFinalTermLimit = $isCreditComplete || $isPotentialGraduate;
        $applicableLimit = $usesFinalTermLimit ? $limits['final'] : $limits['regular'];
        $excessCredits = self::credits(max(0, $totalSelected - $applicableLimit));

        return [
            'compulsory_earned' => $compulsoryEarned,
            'elective_earned' => $electiveEarned,
            'compulsory_selected' => $compulsorySelected,
            'elective_selected' => $electiveSelected,
            'total_selected' => $totalSelected,
            'projected_compulsory' => $projectedCompulsory,
            'projected_elective' => $projectedElective,
            'compulsory_required' => self::credits($compulsoryRequired),
            'elective_required' => self::credits($electiveRequired),
            'is_credit_complete' => $isCreditComplete,
            'is_potential_graduate' => $isPotentialGraduate,
            'uses_final_term_limit' => $usesFinalTermLimit,
            'regular_limit' => $limits['regular'],
            'final_term_limit' => $limits['final'],
            'applicable_limit' => $applicableLimit,
            'exceeds_limit' => $excessCredits > 0,
            'excess_credits' => $excessCredits,
        ];
    }

    private static function credits(float $value): float
    {
        return round(max(0, $value), 2);
    }
}
