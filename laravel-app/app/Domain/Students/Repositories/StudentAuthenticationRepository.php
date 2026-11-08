<?php

namespace App\Domain\Students\Repositories;

interface StudentAuthenticationRepository
{
    /** @return array{student_id:string,full_name:string,group_code:string,class_name:string,district_id:int,district_name:string,level:int,level_name:string}|null */
    public function findStudentForAuthentication(string $citizenId, int $districtId): ?array;
}
