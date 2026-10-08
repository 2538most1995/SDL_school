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

        $cause = trim((string) $finishCause);
        if ($cause === '' || $cause === '0' || $cause === '00') {
            return ['studying', 'กำลังศึกษา'];
        }
        if ($cause === '1') {
            return ['graduated', 'จบการศึกษา'];
        }

        return ['inactive', 'พ้นสภาพ/รอตรวจสอบ'];
    }
}
