<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Student;

interface TermStudentRepository
{
    /** @param list<int> $districtIds
     * @return list<string> Normalized terms, newest first, from the active import batch.
     */
    public function availableTerms(array $districtIds): array;

    /** @param list<int> $districtIds
     * @return list<Student>
     */
    public function studentsForTerm(array $districtIds, string $term): array;
}
