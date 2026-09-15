import type { ExcelSheet } from '../../lib/excel';

export type CategoryKey = 'target_group' | 'gender' | 'level' | 'occupation' | 'nationality' | 'age';

export type FilterOption = {
    value: string;
    label: string;
    count: number;
};

export type StatisticItem = {
    key: string;
    code: string;
    label: string;
    count: number;
    percentage: number;
};

export type RegistrationStatisticsPayload = {
    categories: Array<{ key: CategoryKey; label: string }>;
    selected_category: CategoryKey;
    selected_category_label: string;
    filter_options: Record<CategoryKey, FilterOption[]>;
    applied_filters: Partial<Record<CategoryKey, string>>;
    terms: string[];
    selected_term: string | null;
    summary: {
        registered_students: number;
        category_count: number;
        largest_category: StatisticItem | null;
    };
    items: StatisticItem[];
};

const categoryLabels: Record<CategoryKey, string> = {
    target_group: 'กลุ่มเป้าหมาย',
    gender: 'เพศ',
    level: 'ระดับชั้น',
    occupation: 'อาชีพ',
    nationality: 'สัญชาติ',
    age: 'อายุ',
};

export function canExportRegistrationStatistics(role: string): boolean {
    return role === 'teacher' || role === 'admin';
}

function appliedFilterLabel(payload: RegistrationStatisticsPayload, key: CategoryKey, value: string): string {
    return payload.filter_options[key].find((option) => option.value === value)?.label ?? value;
}

export function buildRegistrationStatisticsSheets(payload: RegistrationStatisticsPayload): ExcelSheet[] {
    const term = payload.selected_term ?? '-';
    const rows = payload.items.map((item, index) => [
        term,
        payload.selected_category_label,
        index + 1,
        item.code,
        item.label,
        item.count,
        item.percentage,
    ]);
    rows.push([
        term,
        payload.selected_category_label,
        '',
        '',
        'รวมทั้งหมด',
        payload.summary.registered_students,
        payload.summary.registered_students > 0 ? 100 : 0,
    ]);

    const filters = Object.entries(payload.applied_filters) as Array<[CategoryKey, string]>;
    const conditionRows: Array<Array<string>> = [
        ['ภาคเรียน', term],
        ['หัวข้อที่ใช้แยกผล', payload.selected_category_label],
        ...(filters.length > 0
            ? filters.map(([key, value]) => [categoryLabels[key], appliedFilterLabel(payload, key, value)])
            : [['ตัวกรอง', 'ทั้งหมด']]),
    ];

    return [
        {
            name: 'สถิติการลงทะเบียน',
            columns: ['ภาคเรียน', 'หัวข้อสถิติ', 'ลำดับ', 'รหัส', 'รายการ', 'จำนวน (คน)', 'สัดส่วน (%)'],
            rows,
        },
        {
            name: 'เงื่อนไข',
            columns: ['เงื่อนไข', 'ค่าที่ใช้'],
            rows: conditionRows,
        },
    ];
}

export function registrationStatisticsFileName(payload: RegistrationStatisticsPayload): string {
    return `สถิตินักศึกษาลงทะเบียน-${payload.selected_term ?? 'ไม่มีภาคเรียน'}-${payload.selected_category_label}`;
}
