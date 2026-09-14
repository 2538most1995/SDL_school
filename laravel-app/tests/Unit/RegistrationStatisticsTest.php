<?php

namespace Tests\Unit;

use App\Domain\Students\Support\RegistrationStatistics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationStatisticsTest extends TestCase
{
    #[DataProvider('labelProvider')]
    public function test_it_maps_itw51_codes_to_readable_labels(string $category, string $code, string $expected): void
    {
        $this->assertSame($expected, RegistrationStatistics::itemLabel($category, $code));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function labelProvider(): iterable
    {
        yield 'prisoner target group' => ['target_group', '07', 'ผู้ต้องขัง'];
        yield 'worker target group' => ['target_group', '09', 'ผู้ใช้แรงงาน'];
        yield 'female' => ['gender', '2', 'หญิง'];
        yield 'upper secondary' => ['level', '3', 'มัธยมศึกษาตอนปลาย'];
        yield 'farmer' => ['occupation', '04', 'เกษตรกร'];
        yield 'thai' => ['nationality', '099', 'ไทย'];
    }

    public function test_payload_keeps_display_order_but_finds_the_actual_largest_category(): void
    {
        $payload = RegistrationStatistics::payload('gender', ['1' => 2, '2' => 6], ['2/2568'], '2/2568');

        $this->assertSame('1', $payload['items'][0]['code']);
        $this->assertSame('2', $payload['summary']['largest_category']['code']);
        $this->assertSame(8, $payload['summary']['registered_students']);
        $this->assertSame(75.0, $payload['summary']['largest_category']['percentage']);
    }
}
