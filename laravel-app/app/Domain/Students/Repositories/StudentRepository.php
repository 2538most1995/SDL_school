<?php

namespace App\Domain\Students\Repositories;

use App\Domain\Students\Models\Grade;
use App\Domain\Students\Models\KpchActivity;
use App\Domain\Students\Models\MoralAssessment;
use App\Domain\Students\Models\RegisteredSubject;
use App\Domain\Students\Models\Student;

interface StudentRepository
{
    /**
     * @param  list<int>|null  $districtIds  Null is reserved for trusted internal audits; requests pass an explicit scope.
     * @return list<Student>
     */
    public function students(?array $districtIds = null): array;

    public function find(string $code, ?int $districtId = null, ?int $level = null): ?Student;

    /** @return list<Grade> */
    public function gradesFor(Student $student): array;

    /**
     * Load academic rows in batches so report pages do not issue one query per
     * student. Results are keyed by "district|level|student-code".
     *
     * @param  list<Student>  $students
     * @return array<string, list<Grade>>
     */
    public function gradesForMany(array $students): array;

    /** @return list<RegisteredSubject> */
    public function subjectsFor(Student $student): array;

    /** @return list<KpchActivity> */
    public function kpchFor(Student $student): array;

    /** @return list<MoralAssessment> */
    public function moralFor(Student $student): array;
}
