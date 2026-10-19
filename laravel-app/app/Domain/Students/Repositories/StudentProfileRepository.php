<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Student;

interface StudentProfileRepository
{
    /** @return array<string, mixed> Additional details for an already-authorized student. */
    public function profileFor(Student $student): array;
}
