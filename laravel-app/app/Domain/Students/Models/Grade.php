<?php

namespace App\Domain\Students\Models;

final readonly class Grade
{
    public function __construct(
        public string $studentCode,
        public string $subjectCode,
        public string $subjectName,
        public float $credits,
        public string $subjectType,
        public string $term,
        public ?string $grade,
        public bool $transferred = false,
        public bool $examAttended = true,
    ) {}

    public function numericGrade(): ?float
    {
        if ($this->grade === null || ! is_numeric($this->grade)) {
            return null;
        }

        return (float) $this->grade;
    }

    public function isPassed(): bool
    {
        $numeric = $this->numericGrade();

        return $numeric !== null ? $numeric >= 1.0 : in_array($this->grade, ['ผ', 'ผ่าน'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'student_code' => $this->studentCode,
            'subject' => [
                'code' => $this->subjectCode,
                'name' => $this->subjectName,
                'credits' => $this->credits,
                'type' => $this->subjectType,
            ],
            'term' => $this->term,
            'grade' => $this->grade,
            'numeric_grade' => $this->numericGrade(),
            'is_passed' => $this->isPassed(),
            'is_transferred' => $this->transferred,
            'exam_attended' => $this->examAttended,
        ];
    }
}
