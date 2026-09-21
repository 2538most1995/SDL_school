<?php

namespace App\Domain\Students\Support;

final class RegistrationStatistics
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'target_group' => 'กลุ่มเป้าหมาย',
        'group' => 'กลุ่มเรียน',
        'gender' => 'เพศ',
        'level' => 'ระดับชั้น',
        'occupation' => 'อาชีพ',
        'nationality' => 'สัญชาติ',
        'age' => 'อายุ',
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
        '10' => 'ไม่ระบุอาชีพ',
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
        '057' => 'กัมพูชา',
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
        $code = self::normalizeCode($category, $code);
        if ($code === '') {
            return 'ไม่ระบุ';
        }

        return match ($category) {
            'target_group' => self::TARGET_GROUPS[$code] ?? "ไม่พบชื่อกลุ่ม (รหัส {$code})",
            'gender' => match (mb_strtoupper($code)) {
                '1', 'M', 'ชาย' => 'ชาย',
                '2', 'F', 'หญิง' => 'หญิง',
                '3' => 'ไม่ระบุเพศ',
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
            'age' => "{$code} ปี",
            default => $code,
        };
    }

    public static function normalizeCode(string $category, mixed $code): string
    {
        $value = trim((string) $code);
        if ($value === '') {
            return '';
        }

        return match ($category) {
            'target_group', 'occupation' => preg_match('/^\d+$/', $value) === 1
                ? str_pad((string) ((int) $value), 2, '0', STR_PAD_LEFT)
                : $value,
            'nationality' => preg_match('/^\d+$/', $value) === 1
                ? str_pad((string) ((int) $value), 3, '0', STR_PAD_LEFT)
                : $value,
            'gender' => mb_strtoupper($value),
            'level' => in_array((int) $value, [1, 2, 3], true) ? (string) ((int) $value) : $value,
            'age' => (preg_match('/^\d{1,3}$/', $value) === 1 && (int) $value > 0 && (int) $value <= 120)
                ? (string) ((int) $value)
                : '',
            default => $value,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function fromRecords(string $category, array $records, array $terms, ?string $selectedTerm, array $filters = []): array
    {
        if (! array_key_exists($category, self::CATEGORY_LABELS)) {
            $category = 'target_group';
        }

        [$filteredRecords, $itemLabels, $filterOptions, $appliedFilters] = self::prepareRecords($records, $filters);

        $counts = [];
        foreach ($filteredRecords as $record) {
            $code = (string) ($record[$category] ?? '');
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return [
            ...self::payload($category, $counts, $terms, $selectedTerm, $itemLabels[$category] ?? []),
            'filter_options' => $filterOptions,
            'applied_filters' => $appliedFilters,
        ];
    }

    /**
     * Build one real cross-tab from the same unique student records. Categories
     * added to an axis become nested tuple parts instead of separate marginal
     * tables, so every cell represents the actual intersection of both axes.
     *
     * @param  list<string>  $rowCategories
     * @param  list<string>  $columnCategories
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function crossTabFromRecords(
        array $rowCategories,
        array $columnCategories,
        array $records,
        array $terms,
        ?string $selectedTerm,
        array $filters = [],
    ): array {
        $rowCategories = self::validCategories($rowCategories, ['level']);
        $columnCategories = self::validCategories($columnCategories, ['group']);
        [$filteredRecords, $itemLabels, $filterOptions, $appliedFilters] = self::prepareRecords($records, $filters);

        $rows = [];
        $columns = [];
        $nonZeroCells = 0;
        $largestCell = null;

        foreach ($filteredRecords as $record) {
            $rowParts = self::tupleParts($rowCategories, $record, $itemLabels);
            $columnParts = self::tupleParts($columnCategories, $record, $itemLabels);
            $rowKey = self::tupleKey($rowParts);
            $columnKey = self::tupleKey($columnParts);

            $rows[$rowKey] ??= [
                'key' => $rowKey,
                'label' => implode(' · ', array_column($rowParts, 'label')),
                'parts' => $rowParts,
                'cells' => [],
                'total' => 0,
            ];
            $columns[$columnKey] ??= [
                'key' => $columnKey,
                'label' => implode(' · ', array_column($columnParts, 'label')),
                'parts' => $columnParts,
                'total' => 0,
            ];

            $rows[$rowKey]['cells'][$columnKey] = ($rows[$rowKey]['cells'][$columnKey] ?? 0) + 1;
            $rows[$rowKey]['total']++;
            $columns[$columnKey]['total']++;
        }

        $rowItems = array_values($rows);
        $columnItems = array_values($columns);
        usort($rowItems, static fn (array $left, array $right): int => strnatcasecmp((string) $left['label'], (string) $right['label']));
        usort($columnItems, static fn (array $left, array $right): int => strnatcasecmp((string) $left['label'], (string) $right['label']));

        foreach ($rowItems as $row) {
            foreach ($row['cells'] as $columnKey => $count) {
                $nonZeroCells++;
                if ($largestCell === null || $count > $largestCell['count']) {
                    $largestCell = [
                        'row_key' => $row['key'],
                        'column_key' => $columnKey,
                        'count' => $count,
                    ];
                }
            }
        }

        return [
            'categories' => self::categories(),
            'row_categories' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::categoryLabel($key)],
                $rowCategories,
            ),
            'column_categories' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::categoryLabel($key)],
                $columnCategories,
            ),
            'terms' => $terms,
            'selected_term' => $selectedTerm,
            'summary' => [
                'registered_students' => count($filteredRecords),
                'row_count' => count($rowItems),
                'column_count' => count($columnItems),
                'non_zero_cells' => $nonZeroCells,
                'largest_cell' => $largestCell,
            ],
            'rows' => $rowItems,
            'columns' => $columnItems,
            'filter_options' => $filterOptions,
            'applied_filters' => $appliedFilters,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, string>>, 2: array<string, list<array<string, mixed>>>, 3: array<string, string>}
     */
    private static function prepareRecords(array $records, array $filters): array
    {
        $itemLabels = [];
        $groupCodeAliases = [];
        $normalizedRecords = array_map(static function (array $record) use (&$itemLabels, &$groupCodeAliases): array {
            foreach (array_keys(self::CATEGORY_LABELS) as $key) {
                $record[$key] = self::normalizeCode($key, $record[$key] ?? '');
            }

            $groupCode = (string) $record['group'];
            $groupLabel = trim((string) ($record['group_label'] ?? ''));
            $groupKey = $groupLabel !== '' ? $groupLabel : $groupCode;
            $record['group'] = $groupKey;
            if ($groupCode !== '') {
                $groupCodeAliases[$groupCode] ??= $groupKey;
            }
            if ($groupKey !== '') {
                $itemLabels['group'][$groupKey] = $groupLabel !== '' ? $groupLabel : $groupKey;
            }

            return $record;
        }, $records);

        $filterOptions = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $filterCategory) {
            $optionCounts = [];
            foreach ($normalizedRecords as $record) {
                $code = (string) ($record[$filterCategory] ?? '');
                $optionCounts[$code] = ($optionCounts[$code] ?? 0) + 1;
            }
            $filterOptions[$filterCategory] = array_map(
                static fn (array $item): array => [
                    'value' => $item['code'],
                    'label' => $item['label'],
                    'count' => $item['count'],
                ],
                self::items($filterCategory, $optionCounts, null, $itemLabels[$filterCategory] ?? []),
            );
        }

        $appliedFilters = [];
        $groupNameFilter = trim((string) ($filters['group_name'] ?? ''));
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            $rawValue = $key === 'group' && $groupNameFilter !== ''
                ? $groupNameFilter
                : ($filters[$key] ?? '');
            if (trim((string) $rawValue) === '') {
                continue;
            }
            $value = self::normalizeCode($key, $rawValue);
            if ($key === 'group' && $groupNameFilter === '' && array_key_exists($value, $groupCodeAliases)) {
                $value = $groupCodeAliases[$value];
            }
            $appliedFilters[$key] = $value;
        }

        $filteredRecords = array_values(array_filter(
            $normalizedRecords,
            static function (array $record) use ($appliedFilters): bool {
                foreach ($appliedFilters as $key => $value) {
                    if ((string) ($record[$key] ?? '') !== $value) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return [$filteredRecords, $itemLabels, $filterOptions, $appliedFilters];
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function validCategories(array $categories, array $fallback): array
    {
        $valid = array_values(array_unique(array_filter(
            $categories,
            static fn (mixed $category): bool => is_string($category) && array_key_exists($category, self::CATEGORY_LABELS),
        )));

        return array_slice($valid === [] ? $fallback : $valid, 0, 3);
    }

    /**
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, string>>  $itemLabels
     * @return list<array{category: string, category_label: string, code: string, label: string}>
     */
    private static function tupleParts(array $categories, array $record, array $itemLabels): array
    {
        return array_map(static function (string $category) use ($record, $itemLabels): array {
            $code = (string) ($record[$category] ?? '');

            return [
                'category' => $category,
                'category_label' => self::categoryLabel($category),
                'code' => $code,
                'label' => $itemLabels[$category][$code] ?? self::itemLabel($category, $code),
            ];
        }, $categories);
    }

    /** @param list<array{category: string, category_label: string, code: string, label: string}> $parts */
    private static function tupleKey(array $parts): string
    {
        return base64_encode((string) json_encode(
            array_map(static fn (array $part): array => [$part['category'], $part['code']], $parts),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $terms
     * @return array<string, mixed>
     */
    public static function payload(string $category, array $counts, array $terms, ?string $selectedTerm, array $itemLabels = []): array
    {
        if (! array_key_exists($category, self::CATEGORY_LABELS)) {
            $category = 'target_group';
        }

        $total = array_sum($counts);
        $items = self::items($category, $counts, $total, $itemLabels);

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

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, code: string, label: string, count: int, percentage: float}>
     */
    private static function items(string $category, array $counts, ?int $total = null, array $itemLabels = []): array
    {
        $total ??= array_sum($counts);
        $items = [];
        foreach ($counts as $rawCode => $count) {
            $code = self::normalizeCode($category, (string) $rawCode);
            $items[] = [
                'key' => $code === '' ? 'unknown' : $code,
                'code' => $code,
                'label' => $itemLabels[$code] ?? self::itemLabel($category, $code),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        usort($items, static function (array $left, array $right) use ($category): int {
            if (in_array($category, ['gender', 'level', 'age'], true)) {
                $order = $category === 'gender'
                    ? ['1' => 1, 'M' => 1, 'ชาย' => 1, '2' => 2, 'F' => 2, 'หญิง' => 2]
                    : ($category === 'level' ? ['1' => 1, '2' => 2, '3' => 3] : []);
                if ($category === 'age') {
                    $comparison = ($left['code'] === '' ? 999 : (int) $left['code']) <=> ($right['code'] === '' ? 999 : (int) $right['code']);
                } else {
                    $comparison = ($order[(string) $left['code']] ?? 99) <=> ($order[(string) $right['code']] ?? 99);
                }
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $countComparison = (int) $right['count'] <=> (int) $left['count'];

            return $countComparison !== 0
                ? $countComparison
                : strnatcasecmp((string) $left['label'], (string) $right['label']);
        });

        return $items;
    }
}
