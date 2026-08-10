<?php

namespace App\Domain\Students\Services;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class SystemStudentAuthenticator
{
    public function __construct(private StudentRepository $students) {}

    public function authenticate(string $citizenIdentifier, string $studentCode, ?int $districtId = null): ?User
    {
        $citizenId = preg_replace('/\D+/u', '', $citizenIdentifier) ?? '';
        $studentCode = trim($studentCode);

        if (strlen($citizenId) !== 13 || $studentCode === '' || mb_strlen($studentCode) > 32) {
            return null;
        }

        $student = $this->students->find($studentCode, $districtId);
        if (! $student instanceof Student
            || $student->citizenId === null
            || ! hash_equals($student->citizenId, $citizenId)) {
            return null;
        }

        return DB::transaction(function () use ($student): ?User {
            $user = User::query()
                ->where('role', 'student')
                ->where('district_id', $student->districtId)
                ->where('student_code', $student->code)
                ->lockForUpdate()
                ->first();

            if ($user?->disabled_at !== null) {
                return null;
            }

            $user ??= new User([
                'username' => $this->internalUsername($student),
                'email' => $this->internalEmail($student),
                // Student credentials are always verified against the current
                // imported record. Keep the local hash random so this internal
                // account cannot be used as an alternative password route.
                'password' => Hash::make(Str::random(64)),
            ]);

            $user->forceFill([
                'name' => $student->fullName(),
                'first_name' => $student->firstName,
                'last_name' => $student->lastName,
                'role' => 'student',
                'district_id' => $student->districtId,
                'assigned_groups' => $student->groupCode === '' ? [] : [$student->groupCode],
                'student_code' => $student->code,
                'auth_source' => 'system_import',
            ])->save();

            return $user->refresh();
        });
    }

    private function internalUsername(Student $student): string
    {
        return sprintf(
            'student-%d-%s',
            $student->districtId,
            substr(hash('sha256', $student->code), 0, 20),
        );
    }

    private function internalEmail(Student $student): string
    {
        return 'student+'.hash('sha256', $student->districtId.'|'.$student->code).'@system.invalid';
    }
}
