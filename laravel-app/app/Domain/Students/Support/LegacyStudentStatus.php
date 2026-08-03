<?php

namespace App\Domain\Students\Support;

final class LegacyStudentStatus
{
    /** @return array{string, string} */
    public static function resolve(?string $finishCause, ?string $transferDate): array
    {
        if (trim((string) $transferDate) !== '') {
            return ['transferred', 'ย้ายสถานศึกษา'];
        }

        return match (trim((string) $finishCause)) {
            '' => ['studying', 'กำลังศึกษา'],
            '1' => ['graduated', 'จบการศึกษา'],
            default => ['inactive', 'พ้นสภาพ/รอตรวจสอบ'],
        };
    }
}
