<?php

namespace App\Domain\Students\Support;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentRepository;
use App\Models\User;

/** Reject code-only identities that resolve to multiple current students. */
final readonly class StudentIdentityGuard
{
    public function __construct(private StudentRepository $students) {}

    public function assertUnambiguous(User $viewer): void
    {
        if ($viewer->role !== 'student') {
            return;
        }

        $districtId = (int) $viewer->district_id;
        $code = trim((string) ($viewer->student_code ?: $viewer->username));
        abort_unless($districtId > 0, 403, 'ไม่พบข้อมูลบัญชีนักศึกษา');
        // District-wide announcements do not require a linked student record.
        // Identity-specific callers still enforce ownership and a nonempty code.
        if ($code === '') {
            return;
        }
        $matches = array_filter($this->students->students([$districtId]),
            static fn (Student $student): bool => $student->districtId === $districtId && hash_equals($student->code, $code));
        abort_if(count($matches) > 1, 403, 'รหัสนักศึกษาซ้ำระหว่างระดับการศึกษา กรุณาติดต่อผู้ดูแลระบบ');
    }
}
