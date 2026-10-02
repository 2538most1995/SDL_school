<?php

namespace App\Domain\Students\Support;

final class GraduationOpportunity
{
    /**
     * Find the smallest set of remaining compulsory subjects that covers the
     * compulsory gap. Electives can be entered manually in the registration
     * form, so their remaining credits are reserved from the same term limit.
     *
     * @param  list<array{code: string, name: string, credits: float}>  $compulsoryCandidates
     * @return array{compulsory_subjects: list<array{code: string, name: string, credits: float}>, compulsory_credits: float, elective_credits_needed: float, total_credits_needed: float}|null
     */
    public static function plan(
        float $compulsoryEarned,
        float $compulsoryRequired,
        float $electiveEarned,
        float $electiveRequired,
        float $termLimit,
        array $compulsoryCandidates,
    ): ?array {
        $compulsoryGap = max(0, (int) round(($compulsoryRequired - $compulsoryEarned) * 100));
        $electiveGap = max(0, (int) round(($electiveRequired - $electiveEarned) * 100));
        $limit = max(0, (int) round($termLimit * 100));
        if ($compulsoryGap + $electiveGap === 0 || $electiveGap > $limit) {
            return null;
        }

        $availableForCompulsory = $limit - $electiveGap;
        $plans = [0 => []];
        foreach ($compulsoryCandidates as $candidate) {
            $credits = (int) round(max(0, $candidate['credits']) * 100);
            if ($credits === 0 || $credits > $availableForCompulsory) {
                continue;
            }
            foreach ($plans as $sum => $subjects) {
                $next = $sum + $credits;
                if ($next <= $availableForCompulsory && ! isset($plans[$next])) {
                    $plans[$next] = [...$subjects, $candidate];
                }
            }
        }

        $possible = array_filter(array_keys($plans), static fn (int $sum): bool => $sum >= $compulsoryGap);
        if ($possible === []) {
            return null;
        }
        $compulsoryCredits = min($possible);

        return [
            'compulsory_subjects' => $plans[$compulsoryCredits],
            'compulsory_credits' => (float) ($compulsoryCredits / 100),
            'elective_credits_needed' => (float) ($electiveGap / 100),
            'total_credits_needed' => (float) (($compulsoryCredits + $electiveGap) / 100),
        ];
    }
}
