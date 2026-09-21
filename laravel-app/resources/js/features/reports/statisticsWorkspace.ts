import type { ExcelSheet } from '../../lib/excel';
import type { CategoryKey, RegistrationStatisticsPayload } from './registrationStatisticsExport';

export type StatisticReportId = 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 | 14 | 15;
export type StatisticOrientation = 'vertical' | 'horizontal';
export type StatisticAxisConfiguration = Record<StatisticOrientation, CategoryKey[]>;

export type StatisticReportDefinition = {
    id: StatisticReportId;
    label: string;
    source: 'new-students' | 'registration-statistics' | 'graduates' | 'transfers' | null;
    level?: 1 | 2;
    unavailableReason?: string;
};

export type StatisticCategoryDefinition = {
    order: number;
    key: CategoryKey | 'learning_method' | 'disability' | 'study_center';
    label: string;
    supported: boolean;
};

export type GenericReportRow = {
    id: string;
    primary: string;
    secondary: string;
    group: string;
    metric: string;
};

export type GenericReportPayload = {
    total: number;
    active: number;
    groups: number;
    terms?: string[];
    selected_term?: string | null;
    rows: GenericReportRow[];
};

export type StatisticResultRow = {
    id: string;
    code: string;
    label: string;
    classification: string;
    count: number;
    percentage: number;
    note: string;
};

export type StatisticResultSummary = {
    sourceTotal: number;
    classificationCount: number;
    categoryCount: number;
    maximum: StatisticResultRow | null;
    classificationTotals: Array<{ classification: string; total: number }>;
};

export type StatisticCrossTabPart = {
    category: CategoryKey;
    category_label: string;
    code: string;
    label: string;
};

export type StatisticCrossTabColumn = {
    key: string;
    label: string;
    parts: StatisticCrossTabPart[];
    total: number;
};

export type StatisticCrossTabRow = {
    key: string;
    label: string;
    parts: StatisticCrossTabPart[];
    cells: Record<string, number>;
    total: number;
};

export type StatisticCrossTabPayload = {
    categories: Array<{ key: CategoryKey; label: string }>;
    row_categories: Array<{ key: CategoryKey; label: string }>;
    column_categories: Array<{ key: CategoryKey; label: string }>;
    terms: string[];
    selected_term: string | null;
    summary: {
        registered_students: number;
        row_count: number;
        column_count: number;
        non_zero_cells: number;
        largest_cell: { row_key: string; column_key: string; count: number } | null;
    };
    rows: StatisticCrossTabRow[];
    columns: StatisticCrossTabColumn[];
    filter_options?: Record<string, unknown[]>;
    applied_filters?: Record<string, string>;
};

export const statisticReports: StatisticReportDefinition[] = [
    { id: 1, label: 'รายงานจำนวนนักศึกษาเข้าใหม่', source: 'new-students' },
    { id: 2, label: 'รายงานจำนวนนักศึกษาขอลงทะเบียน', source: null, unavailableReason: 'ข้อมูลคำขอลงทะเบียนยังไม่มี API ต้นทางแยกจากข้อมูลลงทะเบียนจริง' },
    { id: 3, label: 'รายงานจำนวนนักศึกษาลงทะเบียน', source: 'registration-statistics' },
    { id: 4, label: 'รายงานจำนวนนักศึกษารักษาสภาพ', source: null, unavailableReason: 'ข้อมูลรักษาสภาพยังไม่มีรหัสสถานะที่ยืนยันได้ในชุดนำเข้าปัจจุบัน' },
    { id: 5, label: 'รายงานจำนวนนักศึกษาขาดรักษาสภาพ', source: null, unavailableReason: 'ข้อมูลขาดรักษาสภาพยังไม่มีรหัสสถานะที่ยืนยันได้ในชุดนำเข้าปัจจุบัน' },
    { id: 6, label: 'รายงานจำนวนนักศึกษาหมดสภาพ', source: null, unavailableReason: 'ข้อมูลหมดสภาพยังถูกรวมอยู่ในสถานะพ้นสภาพ/รอตรวจสอบ จึงยังแยกตัวเลขไม่ได้อย่างถูกต้อง' },
    { id: 7, label: 'รายงานจำนวนนักศึกษาลาออก', source: null, unavailableReason: 'ข้อมูลลาออกยังไม่มีรหัสสาเหตุที่ผ่านการยืนยันสำหรับรายงานแยก' },
    { id: 8, label: 'รายงานจำนวนนักศึกษาขึ้นเรียน', source: null, unavailableReason: 'ข้อมูลขึ้นเรียนยังไม่มี API รายงานเฉพาะ' },
    { id: 9, label: 'รายงานจำนวนนักศึกษาขึ้นทะเบียน', source: null, unavailableReason: 'ข้อมูลขึ้นทะเบียนยังไม่มี API รายงานเฉพาะ' },
    { id: 10, label: 'รายงานจำนวนนักศึกษาที่จบ ป.6 ปีการศึกษาที่แล้ว', source: 'graduates', level: 1 },
    { id: 11, label: 'รายงานจำนวนนักศึกษาที่จบ ม.3 ปีการศึกษาที่แล้ว', source: 'graduates', level: 2 },
    { id: 12, label: 'รายงานจำนวนนักศึกษาที่จบ ป.6 สกร. ภาคเรียนที่แล้ว', source: 'graduates', level: 1 },
    { id: 13, label: 'รายงานจำนวนนักศึกษาที่จบ ม.3 สกร. ภาคเรียนที่แล้ว', source: 'graduates', level: 2 },
    { id: 14, label: 'รายงานจำนวนนักศึกษาเทียบโอนกลุ่มเป้าหมายเฉพาะ', source: null, unavailableReason: 'ข้อมูลต้นทางยังไม่ระบุชนิดการเทียบโอนกลุ่มเป้าหมายเฉพาะแยกจากรายการเทียบโอนทั่วไป' },
    { id: 15, label: 'รายงานจำนวนนักศึกษาเทียบโอนความรู้', source: 'transfers' },
];

export const statisticCategories: StatisticCategoryDefinition[] = [
    { order: 1, key: 'level', label: 'ระดับชั้น', supported: true },
    { order: 2, key: 'group', label: 'รหัสกลุ่ม', supported: true },
    { order: 3, key: 'gender', label: 'เพศ', supported: true },
    { order: 4, key: 'age', label: 'อายุ', supported: true },
    { order: 5, key: 'learning_method', label: 'วิธีเรียน', supported: false },
    { order: 6, key: 'occupation', label: 'อาชีพ', supported: true },
    { order: 7, key: 'target_group', label: 'กลุ่มเป้าหมาย', supported: true },
    { order: 8, key: 'nationality', label: 'สัญชาติ', supported: true },
    { order: 9, key: 'disability', label: 'รหัสความพิการ', supported: false },
    { order: 10, key: 'study_center', label: 'จุดการศึกษา', supported: false },
];

export function reportById(id: StatisticReportId): StatisticReportDefinition {
    return statisticReports.find((report) => report.id === id) ?? statisticReports[0];
}

export function supportedCategoryKeys(keys: Array<StatisticCategoryDefinition['key']>): CategoryKey[] {
    const supported = new Set(statisticCategories.filter((category) => category.supported).map((category) => category.key));
    return keys.filter((key): key is CategoryKey => supported.has(key));
}

export function createDefaultAxisConfiguration(): StatisticAxisConfiguration {
    return {
        vertical: ['level'],
        horizontal: ['group'],
    };
}

export function categoriesForOrientation(
    configuration: StatisticAxisConfiguration,
    orientation: StatisticOrientation,
): CategoryKey[] {
    return [...configuration[orientation]];
}

export function summarizeStatisticRows(
    rows: StatisticResultRow[],
    sourceTotal: number,
): StatisticResultSummary {
    const totals = new Map<string, number>();
    let maximum: StatisticResultRow | null = null;

    rows.forEach((row) => {
        totals.set(row.classification, (totals.get(row.classification) ?? 0) + row.count);
        if (!maximum || row.count > maximum.count) maximum = row;
    });

    return {
        sourceTotal,
        classificationCount: totals.size,
        categoryCount: rows.length,
        maximum,
        classificationTotals: Array.from(totals, ([classification, total]) => ({ classification, total })),
    };
}

function groupParts(value: string): { level: string; group: string } {
    const parts = value.split('·').map((part) => part.trim()).filter(Boolean);
    return {
        level: parts[0] ?? 'ไม่ระบุระดับ',
        group: parts[1] ?? parts[0] ?? 'ไม่ระบุกลุ่ม',
    };
}

function categoryLabel(category: CategoryKey): string {
    return statisticCategories.find((item) => item.key === category)?.label ?? category;
}

function genericPart(category: CategoryKey, values: { level: string; group: string }): StatisticCrossTabPart {
    const value = category === 'group' ? values.group : values.level;
    return {
        category,
        category_label: categoryLabel(category),
        code: value,
        label: value,
    };
}

function tupleKey(parts: StatisticCrossTabPart[]): string {
    return JSON.stringify(parts.map((part) => [part.category, part.code]));
}

/** Build the same two-axis structure for generic reports that expose level/group rows. */
export function genericPayloadToCrossTab(
    payload: GenericReportPayload,
    configuration: StatisticAxisConfiguration,
): StatisticCrossTabPayload {
    const usable = (categories: CategoryKey[], fallback: CategoryKey): CategoryKey[] => {
        const filtered = categories.filter((category) => category === 'level' || category === 'group');
        return filtered.length > 0 ? filtered : [fallback];
    };
    const rowKeys = usable(configuration.vertical, 'level');
    const columnKeys = usable(configuration.horizontal, 'group');
    const rows = new Map<string, StatisticCrossTabRow>();
    const columns = new Map<string, StatisticCrossTabColumn>();

    payload.rows.forEach((sourceRow) => {
        const values = groupParts(sourceRow.group);
        const rowParts = rowKeys.map((category) => genericPart(category, values));
        const columnParts = columnKeys.map((category) => genericPart(category, values));
        const rowKey = tupleKey(rowParts);
        const columnKey = tupleKey(columnParts);
        const row = rows.get(rowKey) ?? {
            key: rowKey,
            label: rowParts.map((part) => part.label).join(' · '),
            parts: rowParts,
            cells: {},
            total: 0,
        };
        const column = columns.get(columnKey) ?? {
            key: columnKey,
            label: columnParts.map((part) => part.label).join(' · '),
            parts: columnParts,
            total: 0,
        };
        row.cells[columnKey] = (row.cells[columnKey] ?? 0) + 1;
        row.total += 1;
        column.total += 1;
        rows.set(rowKey, row);
        columns.set(columnKey, column);
    });

    const rowItems = Array.from(rows.values()).sort((left, right) => left.label.localeCompare(right.label, 'th', { numeric: true }));
    const columnItems = Array.from(columns.values()).sort((left, right) => left.label.localeCompare(right.label, 'th', { numeric: true }));
    let largestCell: StatisticCrossTabPayload['summary']['largest_cell'] = null;
    let nonZeroCells = 0;
    rowItems.forEach((row) => Object.entries(row.cells).forEach(([columnKey, count]) => {
        nonZeroCells += 1;
        if (!largestCell || count > largestCell.count) largestCell = { row_key: row.key, column_key: columnKey, count };
    }));

    return {
        categories: [...new Set([...rowKeys, ...columnKeys])].map((key) => ({ key, label: categoryLabel(key) })),
        row_categories: rowKeys.map((key) => ({ key, label: categoryLabel(key) })),
        column_categories: columnKeys.map((key) => ({ key, label: categoryLabel(key) })),
        terms: payload.terms ?? [],
        selected_term: payload.selected_term ?? null,
        summary: {
            registered_students: payload.total,
            row_count: rowItems.length,
            column_count: columnItems.length,
            non_zero_cells: nonZeroCells,
            largest_cell: largestCell,
        },
        rows: rowItems,
        columns: columnItems,
    };
}

export function genericPayloadToStatisticRows(
    payload: GenericReportPayload,
    categories: CategoryKey[],
): StatisticResultRow[] {
    const usableCategories = categories.filter((category) => category === 'level' || category === 'group');
    const selectedCategories: Array<'level' | 'group'> = usableCategories.length > 0
        ? usableCategories as Array<'level' | 'group'>
        : ['level'];
    const total = payload.rows.length;
    const output: StatisticResultRow[] = [];

    selectedCategories.forEach((category) => {
        const counts = new Map<string, number>();
        payload.rows.forEach((row) => {
            const value = groupParts(row.group)[category];
            counts.set(value, (counts.get(value) ?? 0) + 1);
        });
        Array.from(counts.entries())
            .sort((left, right) => right[1] - left[1] || left[0].localeCompare(right[0], 'th'))
            .forEach(([label, count], index) => {
                output.push({
                    id: `${category}-${index}-${label}`,
                    code: category === 'group' ? label : String(index + 1),
                    label,
                    classification: category === 'level' ? 'ระดับชั้น' : 'รหัสกลุ่ม',
                    count,
                    percentage: total > 0 ? Number(((count / total) * 100).toFixed(1)) : 0,
                    note: payload.selected_term ? `ภาคเรียน ${payload.selected_term}` : '',
                });
            });
    });

    return output;
}

export function registrationPayloadsToStatisticRows(payloads: RegistrationStatisticsPayload[]): StatisticResultRow[] {
    return payloads.flatMap((payload) => payload.items.map((item, index) => ({
        id: `${payload.selected_category}-${item.key}-${index}`,
        code: item.code,
        label: item.label,
        classification: payload.selected_category_label,
        count: item.count,
        percentage: item.percentage,
        note: payload.selected_term ? `ภาคเรียน ${payload.selected_term}` : '',
    })));
}

export function buildStatisticsWorkspaceSheets(
    report: StatisticReportDefinition,
    term: string,
    district: string,
    rows: StatisticResultRow[],
    options: {
        sourceTotal?: number;
        orientation?: StatisticOrientation;
        categoryLabels?: string[];
    } = {},
): ExcelSheet[] {
    const firstClassification = rows[0]?.classification;
    const inferredTotal = rows
        .filter((row) => row.classification === firstClassification)
        .reduce((sum, row) => sum + row.count, 0);
    const total = options.sourceTotal ?? inferredTotal;
    const orientationLabel = options.orientation === 'horizontal' ? 'แนวนอน' : 'แนวตั้ง';
    return [
        {
            name: 'รายงานสถิติ',
            columns: ['ลำดับ', 'รหัส', 'รายการ', 'ประเภทการจำแนก', 'จำนวน', 'ร้อยละ', 'หมายเหตุ'],
            rows: [
                ...rows.map((row, index) => [index + 1, row.code, row.label, row.classification, row.count, row.percentage, row.note]),
                ['', '', 'รวมทั้งหมด (ผู้เรียนไม่ซ้ำ)', '', total, total > 0 ? 100 : 0, 'สรุปจากข้อมูลต้นทาง'],
            ],
        },
        {
            name: 'เงื่อนไขรายงาน',
            columns: ['เงื่อนไข', 'ค่าที่ใช้'],
            rows: [
                ['รายงาน', `${report.id}. ${report.label}`],
                ['พื้นที่ข้อมูล', district],
                ['ภาคเรียน', term || '-'],
                ['รูปแบบการแสดงผล', orientationLabel],
                ['มิติข้อมูล', options.categoryLabels?.join(', ') || '-'],
                ['จำนวนแถวผลลัพธ์', rows.length],
                ['รวมผู้เรียนทั้งหมด (ไม่ซ้ำ)', total],
            ],
        },
    ];
}

export function buildStatisticsCrossTabSheets(
    report: StatisticReportDefinition,
    term: string,
    district: string,
    crossTab: StatisticCrossTabPayload,
): ExcelSheet[] {
    const rowAxis = crossTab.row_categories.map((category) => category.label).join(' › ');
    const columnAxis = crossTab.column_categories.map((category) => category.label).join(' › ');
    return [
        {
            name: 'ตารางสถิติสองแกน',
            columns: [
                `แกนตั้ง: ${rowAxis}`,
                ...crossTab.columns.map((column) => column.label),
                'รวมแถว',
            ],
            rows: [
                ...crossTab.rows.map((row) => [
                    row.label,
                    ...crossTab.columns.map((column) => row.cells[column.key] ?? 0),
                    row.total,
                ]),
                [
                    'รวมคอลัมน์',
                    ...crossTab.columns.map((column) => column.total),
                    crossTab.summary.registered_students,
                ],
            ],
        },
        {
            name: 'เงื่อนไขรายงาน',
            columns: ['เงื่อนไข', 'ค่าที่ใช้'],
            rows: [
                ['รายงาน', `${report.id}. ${report.label}`],
                ['พื้นที่ข้อมูล', district],
                ['ภาคเรียน', term || '-'],
                ['แกนตั้ง', rowAxis || '-'],
                ['แกนนอน', columnAxis || '-'],
                ['ชุดแถวที่แสดง', crossTab.summary.row_count],
                ['ชุดคอลัมน์ที่แสดง', crossTab.summary.column_count],
                ['ช่องข้อมูลที่มีค่า', crossTab.summary.non_zero_cells],
                ['รวมผู้เรียนทั้งหมด (ไม่ซ้ำ)', crossTab.summary.registered_students],
            ],
        },
    ];
}
