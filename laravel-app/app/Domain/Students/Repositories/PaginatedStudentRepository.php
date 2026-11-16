<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Student;
use App\Models\User;

interface PaginatedStudentRepository
{
    /** @return array{items: list<Student>, meta: array<string, mixed>} */
    public function directoryPage(User $viewer, array $filters): array;

    public function directoryStudent(User $viewer, string $code, ?int $level, ?string $term): ?Student;
}
