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
        $targetGroups = [
            '01' => 'เด็กด้อยโอกาส',
            '02' => 'สตรีกลุ่มเสี่ยง',
            '03' => 'ผู้สูงอายุ',
            '04' => 'คนพิการ',
            '05' => 'ผู้นำท้องถิ่น',
            '06' => 'องค์การบริหารส่วนตำบล',
            '07' => 'ผู้ต้องขัง',
            '08' => 'ทหารกองประจำการ',
            '09' => 'ผู้ใช้แรงงาน',
            '10' => 'แรงงานต่างด้าว (อายุ 16 ปีขึ้นไป)',
            '11' => 'เกษตรกร',
            '12' => 'ชาวไทยภูเขา',
            '13' => 'ปอเนาะ',
            '14' => 'ชุมชนแออัด',
            '15' => 'อาสาสมัครสาธารณสุขประจำหมู่บ้าน',
            '16' => 'ผู้ปฏิบัติศาสนกิจ',
            '17' => 'อื่นๆ',
            '18' => 'คนไทยในต่างประเทศ',
            '19' => 'เยาวชน',
            '20' => 'องค์กรปกครองส่วนท้องถิ่น',
            '21' => 'เด็กในสถานพินิจ',
            '22' => 'สหกรณ์เครดิตยูเนี่ยน',
            '23' => 'English Program',
            '24' => 'เด็กไม่มีสัญชาติไทย (อายุ 8-15 ปี)',
            '25' => 'ชาวเล',
            '26' => 'เด็กเร่ร่อน',
            '27' => 'ผู้หนีภัยในพื้นที่พักพิงชั่วคราว',
            '28' => 'เด็กบนพื้นที่สูง',
            '29' => 'พนักงานรักษาความปลอดภัย',
            '30' => 'เด็กออกกลางคัน',
            '31' => 'พระ/นักบวช',
            '32' => 'พิการเรียนร่วม',
        ];

        foreach ($targetGroups as $code => $label) {
            $normalizedCode = str_pad((string) $code, 2, '0', STR_PAD_LEFT);
            yield "target group {$normalizedCode}" => ['target_group', $normalizedCode, $label];
        }

        yield 'female' => ['gender', '2', 'หญิง'];
        yield 'unspecified gender' => ['gender', '3', 'ไม่ระบุเพศ'];
        yield 'upper secondary' => ['level', '3', 'มัธยมศึกษาตอนปลาย'];
        yield 'farmer' => ['occupation', '04', 'เกษตรกร'];
        yield 'thai' => ['nationality', '099', 'ไทย'];
        yield 'cambodian' => ['nationality', '057', 'กัมพูชา'];
        yield 'age' => ['age', '32', '32 ปี'];
    }

    public function test_payload_keeps_display_order_but_finds_the_actual_largest_category(): void
    {
        $payload = RegistrationStatistics::payload('gender', ['1' => 2, '2' => 6], ['2/2568'], '2/2568');

        $this->assertSame('1', $payload['items'][0]['code']);
        $this->assertSame('2', $payload['summary']['largest_category']['code']);
        $this->assertSame(8, $payload['summary']['registered_students']);
        $this->assertSame(75.0, $payload['summary']['largest_category']['percentage']);
    }

    public function test_records_can_be_filtered_by_multiple_dimensions_and_include_filter_options(): void
    {
        $records = [
            ['target_group' => '30', 'group' => 'SENA-M3-B', 'group_label' => 'เสนา ม.ปลาย B', 'gender' => '1', 'level' => '3', 'occupation' => '05', 'nationality' => '099', 'age' => '20'],
            ['target_group' => '30', 'group' => 'SENA-M3-B', 'group_label' => 'เสนา ม.ปลาย B', 'gender' => '2', 'level' => '3', 'occupation' => '05', 'nationality' => '099', 'age' => '21'],
            ['target_group' => '09', 'group' => 'SENA-M2-A', 'group_label' => 'เสนา ม.ต้น A', 'gender' => '1', 'level' => '2', 'occupation' => '04', 'nationality' => '057', 'age' => '20'],
        ];

        $payload = RegistrationStatistics::fromRecords(
            'target_group',
            $records,
            ['2/2568'],
            '2/2568',
            ['gender' => '1', 'age' => 20],
        );

        $this->assertSame(2, $payload['summary']['registered_students']);
        $this->assertSame(['gender' => '1', 'age' => '20'], $payload['applied_filters']);
        $this->assertSame('เด็กออกกลางคัน', collect($payload['items'])->firstWhere('code', '30')['label']);
        $this->assertCount(2, $payload['filter_options']['age']);
    }

    public function test_group_category_uses_group_code_and_readable_name_for_filtering(): void
    {
        $records = [
            ['group' => 'SENA-M3-A', 'group_label' => 'เสนา ม.ปลาย A'],
            ['group' => 'SENA-M3-B', 'group_label' => 'เสนา ม.ปลาย B'],
            ['group' => 'SENA-M3-B', 'group_label' => 'เสนา ม.ปลาย B'],
        ];

        $payload = RegistrationStatistics::fromRecords(
            'group',
            $records,
            ['2/2568'],
            '2/2568',
            ['group' => 'เสนา ม.ปลาย B'],
        );

        $this->assertSame('กลุ่มเรียน', $payload['selected_category_label']);
        $this->assertSame('เสนา ม.ปลาย B', $payload['applied_filters']['group']);
        $this->assertSame(2, $payload['summary']['registered_students']);
        $this->assertSame('SENA-M3-B', $payload['items'][0]['code']);
        $this->assertSame('เสนา ม.ปลาย B', $payload['items'][0]['label']);
        $this->assertSame('เสนา ม.ปลาย B', collect($payload['filter_options']['group'])->firstWhere('value', 'SENA-M3-B')['label']);
    }

    public function test_group_filter_prefers_exact_code_and_supports_duplicate_names(): void
    {
        $records = [
            ['group' => 'A', 'group_label' => 'B'],
            ['group' => 'B', 'group_label' => 'กลุ่มบี'],
            ['group' => 'C', 'group_label' => 'ชื่อซ้ำ'],
            ['group' => 'D', 'group_label' => 'ชื่อซ้ำ'],
        ];

        $byCode = RegistrationStatistics::fromRecords('group', $records, [], null, ['group' => 'B']);
        $byName = RegistrationStatistics::fromRecords('group', $records, [], null, ['group' => 'ชื่อซ้ำ']);

        $this->assertSame(1, $byCode['summary']['registered_students']);
        $this->assertSame('B', $byCode['items'][0]['code']);
        $this->assertSame(2, $byName['summary']['registered_students']);
        $this->assertEqualsCanonicalizing(['C', 'D'], array_column($byName['items'], 'code'));
    }
}
