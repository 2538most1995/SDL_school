import type { ExcelSheet } from '../../lib/excel';
import type { CategoryKey, RegistrationStatisticsPayload } from './registrationStatisticsExport';

export type StatisticReportId = 1 | 2 | 3 | 4 | 5;
export type StatisticOrientation = 'vertical' | 'horizontal';
export type StatisticAxisConfiguration = Record<StatisticOrientation, CategoryKey[]>;

export type StatisticReportDefinition = {
    id: StatisticReportId;
    label: string;
    source: 'new-students' | 'registration-statistics' | 'graduates' | 'expected-graduates' | 'transfers';
};

export type StatisticCategoryDefinition = {
    order: number;
    key: CategoryKey;
    label: string;
};

export type GenericReportRow = {
    id: string;
    student_id?: string;
    name?: string;
    primary: string;
    secondary: string;
    group: string;
    group_id?: string;
    group_name?: string;
    metric: string;
    entity_key?: string;
    level?: string;
    group_code?: string;
    group_label?: string;
    gender?: string;
    examStatus?: string;
    nnet?: string;
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
    studentsByCell?: Record<string, GenericReportRow[]>;
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
    { id: 2, label: 'รายงานจำนวนนักศึกษาลงทะเบียน', source: 'registration-statistics' },
    { id: 3, label: 'รายงานจำนวนนักศึกษาจบการศึกษา', source: 'graduates' },
    { id: 4, label: 'รายงานจำนวนนักศึกษาคาดว่าจะจบ', source: 'expected-graduates' },
    { id: 5, label: 'รายงานจำนวนนักศึกษาที่มีรายการเทียบโอน', source: 'transfers' },
];

export const statisticCategories: StatisticCategoryDefinition[] = [
    { order: 1, key: 'level', label: 'ระดับชั้น' },
    { order: 2, key: 'group', label: 'กลุ่มเรียน' },
    { order: 3, key: 'gender', label: 'เพศ' },
    { order: 4, key: 'age', label: 'อายุ' },
    { order: 5, key: 'occupation', label: 'อาชีพ' },
    { order: 6, key: 'target_group', label: 'กลุ่มเป้าหมาย' },
    { order: 7, key: 'nationality', label: 'สัญชาติ' },
    { order: 8, key: 'nnet', label: 'สถานะ N-Net / E-Exam' },
];

export function reportById(id: StatisticReportId): StatisticReportDefinition {
    return statisticReports.find((report) => report.id === id) ?? statisticReports[0];
}

export function categoriesForReport(report: StatisticReportDefinition): StatisticCategoryDefinition[] {
    if (report.source === 'registration-statistics') {
        return statisticCategories.filter((category) => category.key !== 'nnet');
    }
    if (report.source === 'expected-graduates') {
        return statisticCategories.filter((category) => category.key === 'level' || category.key === 'group' || category.key === 'gender' || category.key === 'nnet');
    }
    return statisticCategories.filter((category) => category.key === 'level' || category.key === 'group' || category.key === 'gender');
}

export function supportedCategoryKeys(keys: Array<StatisticCategoryDefinition['key']>): CategoryKey[] {
    const supported = new Set(statisticCategories.map((category) => category.key));
    return keys.filter((key): key is CategoryKey => supported.has(key));
}

export function normalizeAxisConfiguration(
    report: StatisticReportDefinition,
    configuration: StatisticAxisConfiguration,
): StatisticAxisConfiguration {
    const supported = new Set(categoriesForReport(report).map((category) => category.key));
    const normalize = (keys: CategoryKey[], fallback: CategoryKey): CategoryKey[] => {
        const unique = keys.filter((key, index) => supported.has(key) && keys.indexOf(key) === index).slice(0, 3);
        return unique.length > 0 ? unique : [fallback];
    };
    return {
        vertical: normalize(configuration.vertical, 'level'),
        horizontal: normalize(configuration.horizontal, 'group'),
    };
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

function groupParts(row: GenericReportRow): { level: string; group: string; gender: string; nnet: string } {
    const nnet = row.examStatus ?? row.nnet ?? 'มีสิทธิ์สอบ';
    const gender = row.gender || 'ไม่ระบุเพศ';
    if (row.level || row.group_label || row.group_code) {
        return {
            level: row.level || 'ไม่ระบุระดับ',
            group: row.group_label || row.group_code || 'ไม่ระบุกลุ่ม',
            gender,
            nnet,
        };
    }
    const value = row.group;
    const parts = value.split('·').map((part) => part.trim()).filter(Boolean);
    return {
        level: parts[0] ?? 'ไม่ระบุระดับ',
        group: parts[1] ?? parts[0] ?? 'ไม่ระบุกลุ่ม',
        gender,
        nnet,
    };
}

function categoryLabel(category: CategoryKey): string {
    return statisticCategories.find((item) => item.key === category)?.label ?? category;
}

function genericPart(category: CategoryKey, values: { level: string; group: string; gender: string; nnet: string }): StatisticCrossTabPart {
    const value = category === 'group' ? values.group : category === 'nnet' ? values.nnet : category === 'gender' ? values.gender : values.level;
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
        const filtered = categories.filter((category) => category === 'level' || category === 'group' || category === 'gender' || category === 'nnet');
        return filtered.length > 0 ? filtered : [fallback];
    };
    const rowKeys = usable(configuration.vertical, 'level');
    const columnKeys = usable(configuration.horizontal, 'group');
    const rows = new Map<string, StatisticCrossTabRow>();
    const columns = new Map<string, StatisticCrossTabColumn>();

    const sourceEntities = new Set<string>();
    const cellEntities = new Map<string, Set<string>>();
    payload.rows.forEach((sourceRow) => {
        const values = groupParts(sourceRow);
        const entityKey = sourceRow.entity_key || sourceRow.id;
        sourceEntities.add(entityKey);
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
            studentsByCell: {},
        };
        const column = columns.get(columnKey) ?? {
            key: columnKey,
            label: columnParts.map((part) => part.label).join(' · '),
            parts: columnParts,
            total: 0,
        };
        const cellKey = `${rowKey}\u0000${columnKey}`;
        const entities = cellEntities.get(cellKey) ?? new Set<string>();
        if (!entities.has(entityKey)) {
            entities.add(entityKey);
            row.cells[columnKey] = (row.cells[columnKey] ?? 0) + 1;
            row.total += 1;
            column.total += 1;
            row.studentsByCell = row.studentsByCell ?? {};
            row.studentsByCell[columnKey] = row.studentsByCell[columnKey] ?? [];
            row.studentsByCell[columnKey].push(sourceRow);
        }
        cellEntities.set(cellKey, entities);
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
            registered_students: sourceEntities.size,
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
    const usableCategories = categories.filter((category) => category === 'level' || category === 'group' || category === 'gender' || category === 'nnet');
    const selectedCategories: Array<'level' | 'group' | 'gender' | 'nnet'> = usableCategories.length > 0
        ? usableCategories as Array<'level' | 'group' | 'gender' | 'nnet'>
        : ['level'];
    const total = payload.rows.length;
    const output: StatisticResultRow[] = [];

    selectedCategories.forEach((category) => {
        const counts = new Map<string, number>();
        payload.rows.forEach((row) => {
            const value = groupParts(row)[category];
            counts.set(value, (counts.get(value) ?? 0) + 1);
        });
        Array.from(counts.entries())
            .sort((left, right) => right[1] - left[1] || left[0].localeCompare(right[0], 'th'))
            .forEach(([label, count], index) => {
                output.push({
                    id: `${category}-${index}-${label}`,
                    code: category === 'group' ? label : String(index + 1),
                    label,
                    classification: category === 'level' ? 'ระดับชั้น' : category === 'group' ? 'รหัสกลุ่ม' : category === 'gender' ? 'เพศ' : 'สถานะ N-Net / E-Exam',
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
