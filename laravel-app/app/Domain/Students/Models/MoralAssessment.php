<?php

namespace App\Domain\Students\Models;

final readonly class MoralAssessment
{
    /** @param array<int, array<string, mixed>> $categories */
    public function __construct(
        public string $studentCode,
        public string $term,
        public array $categories,
        public float $score,
        public float $maximumScore,
        public string $result,
        public ?string $assessedOn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'student_code' => $this->studentCode,
            'term' => $this->term,
            'categories' => $this->categories,
            'summary' => [
                'score' => $this->score,
                'maximum_score' => $this->maximumScore,
                'percent' => $this->maximumScore > 0
                    ? round(($this->score / $this->maximumScore) * 100, 1)
                    : 0.0,
                'result' => $this->result,
            ],
            'assessed_on' => $this->assessedOn,
        ];
    }
}
