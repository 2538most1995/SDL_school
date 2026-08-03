<?php

namespace App\Domain\Students\Models;

final readonly class KpchActivity
{
    public function __construct(
        public string $studentCode,
        public string $id,
        public string $name,
        public string $term,
        public float $hours,
        public string $category,
        public ?string $completedOn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_code' => $this->studentCode,
            'name' => $this->name,
            'term' => $this->term,
            'hours' => $this->hours,
            'category' => $this->category,
            'completed_on' => $this->completedOn,
        ];
    }
}
