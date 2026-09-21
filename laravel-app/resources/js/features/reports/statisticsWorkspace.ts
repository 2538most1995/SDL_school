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
