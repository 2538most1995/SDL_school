<?php

namespace App\Domain\Students\Models;

final readonly class RegisteredSubject
{
    public function __construct(
        public string $studentCode,
        public string $code,
        public string $name,
        public float $credits,
        public string $type,
        public string $term,
        public string $registrationStatus,
        public bool $transferred,
        public ?string $grade,
        public bool $examAttended,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'student_code' => $this->studentCode,
            'code' => $this->code,
            'name' => $this->name,
            'credits' => $this->credits,
            'type' => $this->type,
            'term' => $this->term,
            'registration_status' => $this->registrationStatus,
            'is_transferred' => $this->transferred,
            'grade' => $this->grade,
            'exam_attended' => $this->examAttended,
        ];
    }
}
