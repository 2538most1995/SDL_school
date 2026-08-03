<?php

namespace Tests\Unit;

use App\Domain\Students\Support\LegacyStudentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyStudentStatusTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_it_uses_the_original_legacy_status_rules(
        ?string $finishCause,
        ?string $transferDate,
        string $code,
        string $label,
    ): void {
        $this->assertSame([$code, $label], LegacyStudentStatus::resolve($finishCause, $transferDate));
    }

    public static function statuses(): iterable
    {
        yield 'transfer date wins over finish cause' => ['1', '20260101', 'transferred', 'ย้ายสถานศึกษา'];
        yield 'blank finish cause means studying' => ['', '', 'studying', 'กำลังศึกษา'];
        yield 'null finish cause means studying' => [null, null, 'studying', 'กำลังศึกษา'];
        yield 'finish cause one means graduated' => ['1', '', 'graduated', 'จบการศึกษา'];
        yield 'finish cause zero is inactive' => ['0', '', 'inactive', 'พ้นสภาพ/รอตรวจสอบ'];
        yield 'any other finish cause is inactive' => ['2', '', 'inactive', 'พ้นสภาพ/รอตรวจสอบ'];
    }
}
