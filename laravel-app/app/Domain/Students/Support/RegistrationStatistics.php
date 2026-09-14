<?php

namespace App\Domain\Students\Support;

final class RegistrationStatistics
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'target_group' => 'กลุ่มเป้าหมาย',
        'gender' => 'เพศ',
        'level' => 'ระดับชั้น',
        'occupation' => 'อาชีพ',
        'nationality' => 'สัญชาติ',
    ];

    /** @var array<string, string> */
    private const TARGET_GROUPS = [
        '00' => 'ไม่มีกลุ่มเป้าหมาย',
        '01' => 'เด็กด้อยโอกาส',
        '02' => 'สตรีกลุ่มเสี่ยง',
        '03' => 'ผู้สูงอายุ',
        '04' => 'คนพิการ',
        '05' => 'ผู้นำท้องถิ่น',
        '06' => 'องค์การบริหารส่วนตำบล',
        '07' => 'ผู้ต้องขัง',
        '08' => 'ทหารกองประจำการ',
        '09' => 'ผู้ใช้แรงงาน',
        '10' => 'แรงงานต่างด้าว',
        '11' => 'เกษตรกร',
        '12' => 'ชาวไทยภูเขา',
        '13' => 'ปอเนาะ',
        '14' => 'ชุมชนแออัด',
        '15' => 'อาสาสมัครสาธารณสุขประจำหมู่บ้าน',
        '16' => 'ผู้ปฏิบัติศาสนกิจ',
        '17' => 'อื่น ๆ',
        '18' => 'คนไทยในต่างประเทศ',
        '19' => 'เยาวชน',
    ];

    /** @var array<string, string> */
    private const OCCUPATIONS = [
        '00' => 'ไม่ระบุ',
        '01' => 'รับราชการ',
        '02' => 'พนักงานรัฐวิสาหกิจ',
        '03' => 'นักธุรกิจ/ค้าขาย',
        '04' => 'เกษตรกร',
        '05' => 'รับจ้าง',
        '06' => 'อื่น ๆ',
        '07' => 'ไม่ได้ประกอบอาชีพ',
        '08' => 'พนักงาน/เจ้าหน้าที่ของรัฐ',
        '09' => 'ข้าราชการ/พนักงานของรัฐเกษียณ',
    ];

    /**
     * Codes observed in the ITW51 student dataset. Unknown codes remain visible
     * in the response instead of being guessed or silently merged.
     *
     * @var array<string, string>
     */
    private const NATIONALITIES = [
        '000' => 'ไม่ระบุ',
        '044' => 'จีน',
        '048' => 'เมียนมา',
        '056' => 'ลาว',
        '099' => 'ไทย',
    ];

    /** @return list<array{key: string, label: string}> */
    public static function categories(): array
    {
        return array_map(
            static fn (string $label, string $key): array => ['key' => $key, 'label' => $label],
            self::CATEGORY_LABELS,
            array_keys(self::CATEGORY_LABELS),
        );
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? self::CATEGORY_LABELS['target_group'];
    }

    public static function itemLabel(string $category, string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return 'ไม่ระบุ';
        }

        return match ($category) {
            'target_group' => self::TARGET_GROUPS[$code] ?? "ไม่พบชื่อกลุ่ม (รหัส {$code})",
            'gender' => match (mb_strtoupper($code)) {
                '1', 'M', 'ชาย' => 'ชาย',
                '2', 'F', 'หญิง' => 'หญิง',
                default => "ไม่ระบุ (รหัส {$code})",
            },
            'level' => match ($code) {
                '1' => 'ประถมศึกษา',
                '2' => 'มัธยมศึกษาตอนต้น',
                '3' => 'มัธยมศึกษาตอนปลาย',
                default => "ไม่ทราบระดับ (รหัส {$code})",
            },
            'occupation' => self::OCCUPATIONS[$code] ?? "ไม่พบชื่ออาชีพ (รหัส {$code})",
            'nationality' => self::NATIONALITIES[$code] ?? (preg_match('/^\d+$/', $code) === 1 ? "ไม่พบชื่อสัญชาติ (รหัส {$code})" : $code),
            default => $code,
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $terms
     * @return array<string, mixed>
     */
    public static function payload(string $category, array $counts, array $terms, ?string $selectedTerm): array
    {
        if (! array_key_exists($category, self::CATEGORY_LABELS)) {
            $category = 'target_group';
        }

        $total = array_sum($counts);
        $items = [];
        foreach ($counts as $rawCode => $count) {
            $code = (string) $rawCode;
            $items[] = [
                'key' => $code === '' ? 'unknown' : $code,
                'code' => $code,
                'label' => self::itemLabel($category, $code),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        usort($items, static function (array $left, array $right) use ($category): int {
            if (in_array($category, ['gender', 'level'], true)) {
                $order = $category === 'gender'
                    ? ['1' => 1, 'M' => 1, 'ชาย' => 1, '2' => 2, 'F' => 2, 'หญิง' => 2]
                    : ['1' => 1, '2' => 2, '3' => 3];
                $comparison = ($order[(string) $left['code']] ?? 99) <=> ($order[(string) $right['code']] ?? 99);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $countComparison = (int) $right['count'] <=> (int) $left['count'];

            return $countComparison !== 0
                ? $countComparison
                : strnatcasecmp((string) $left['label'], (string) $right['label']);
        });

        $largestCategory = collect($items)->sortByDesc('count')->first();

        return [
            'categories' => self::categories(),
            'selected_category' => $category,
            'selected_category_label' => self::categoryLabel($category),
            'terms' => $terms,
            'selected_term' => $selectedTerm,
            'summary' => [
                'registered_students' => $total,
                'category_count' => count($items),
                'largest_category' => $largestCategory,
            ],
            'items' => $items,
        ];
    }
}
