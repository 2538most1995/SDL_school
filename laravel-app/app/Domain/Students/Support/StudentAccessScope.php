<?php

namespace App\Domain\Students\Support;

use App\Domain\Students\Models\Student;
use App\Models\User;

final readonly class StudentAccessScope
{
    /** @param list<string> $groupCodes */
    private function __construct(
        private string $role,
        private ?int $districtId,
        private array $groupCodes,
        private ?string $studentCode,
    ) {}

    public static function forUser(User $user): self
    {
        $groups = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($user->assigned_groups) ? $user->assigned_groups : [],
        )));

        return new self(
            role: (string) $user->role,
            districtId: $user->relationLoaded('selectedDistrictContext')
                ? (int) $user->getRelation('selectedDistrictContext')
                : ($user->district_id !== null ? (int) $user->district_id : null),
            groupCodes: $groups,
            studentCode: $user->role === 'student' ? trim((string) ($user->student_code ?: $user->username)) : null,
        );
    }

    public function allows(Student $student): bool
    {
        if ($this->role === 'super_admin') {
            return $this->districtId !== null && $student->districtId === $this->districtId;
        }

        if ($this->districtId === null || $student->districtId !== $this->districtId) {
            return false;
        }

        return match ($this->role) {
            'admin' => true,
            'teacher' => in_array($student->groupCode, $this->groupCodes, true)
                || in_array($student->groupName, $this->groupCodes, true),
            'student' => $this->studentCode !== '' && hash_equals($student->code, $this->studentCode),
            default => false,
        };
    }
}
