import type { ExcelSheet } from '../../lib/excel';

export type RegistrationGroupRow = {
    subject_id: string;
    code: string;
    name: string;
    level: string;
    group_code: string;
    group_name: string;
    registered_students: number;
};

export type RegistrationExportRow = {
    id: string;
    primary: string;
    secondary: string;
    group: string;
    registeredCount?: number;
    level?: string;
    group_label?: string;
    group_code?: string;
};

export function registrationReduction(count: number, percent: number): number {
    if (!Number.isFinite(percent) || percent < 0 || percent > 100) throw new Error('เปอร์เซ็นต์ต้องอยู่ระหว่าง 0 ถึง 100');
    return Math.round(count * percent / 100);
}

/** Allocate whole-person reductions by largest remainder so group totals reconcile. */
export function reducedRegistrationGroups(groups: RegistrationGroupRow[], percentages: Record<string, number>) {
    const result = groups.map((row) => ({ ...row, percent: percentages[row.subject_id] ?? 0, removed: 0 }));
    const subjects = new Map<string, typeof result>();
    for (const row of result) {
        const subject = subjects.get(row.subject_id) ?? [];
        subject.push(row);
        subjects.set(row.subject_id, subject);
    }
    for (const rows of subjects.values()) {
        const total = rows.reduce((sum, row) => sum + row.registered_students, 0);
        const target = registrationReduction(total, rows[0].percent);
        for (const row of rows) row.removed = Math.floor(row.registered_students * rows[0].percent / 100);
        const remaining = target - rows.reduce((sum, row) => sum + row.removed, 0);
        const ranked = [...rows].sort((a, b) =>
            (b.registered_students * b.percent / 100 - b.removed) - (a.registered_students * a.percent / 100 - a.removed)
            || a.group_code.localeCompare(b.group_code, 'th'));
        for (let index = 0; index < remaining; index++) ranked[index].removed++;
    }
    return result;
}

export function buildRegisteredSubjectsSheets(
    view: 'subject' | 'student', rows: RegistrationExportRow[], groups: RegistrationGroupRow[],
    percentages: Record<string, number>, term: string,
): ExcelSheet[] {
    if (view === 'student') {
        const columns = ['ภาคเรียน', 'รหัสนักศึกษา', 'ชื่อ - สกุล', 'ระดับชั้น', 'กลุ่มเรียน', 'รหัสกลุ่มเรียน', 'วิชาลงทะเบียน'];
        const values = (row: RegistrationExportRow) => [term, row.secondary, row.primary, row.level ?? '', row.group_label ?? '', row.group_code ?? '', row.registeredCount ?? 0];
        const grouped = [...rows].sort((a, b) => (a.group_code ?? '').localeCompare(b.group_code ?? '', 'th') || (a.level ?? '').localeCompare(b.level ?? '', 'th') || a.secondary.localeCompare(b.secondary, 'th'));
        return [
            { name: 'รายคน', columns, rows: rows.map(values) },
            { name: 'แยกกลุ่มเรียน', columns, rows: grouped.map(values) },
        ];
    }
    const ids = new Set(rows.map((row) => row.id));
    const grouped = reducedRegistrationGroups(groups.filter((row) => ids.has(row.subject_id)), percentages)
        .sort((a, b) => a.group_code.localeCompare(b.group_code, 'th') || a.level.localeCompare(b.level, 'th') || a.code.localeCompare(b.code, 'th'));
    return [
        {
            name: 'รายวิชา',
            columns: ['ภาคเรียน', 'รหัสวิชา', 'รายวิชา', 'ระดับ', 'ผู้ลงทะเบียนจริง', 'ตัด (%)', 'จำนวนที่ตัด', 'ผู้ลงทะเบียนหลังตัด'],
            rows: rows.map((row) => {
                const count = row.registeredCount ?? 0;
                const percent = percentages[row.id] ?? 0;
                const removed = registrationReduction(count, percent);
                return [term, row.secondary, row.primary, row.group, count, percent, removed, count - removed];
            }),
        },
        {
            name: 'แยกกลุ่มเรียน',
            columns: ['ภาคเรียน', 'รหัสกลุ่มเรียน', 'กลุ่มเรียน', 'ระดับ', 'รหัสวิชา', 'รายวิชา', 'ผู้ลงทะเบียนจริง', 'ตัด (%)', 'จำนวนที่ตัด', 'ผู้ลงทะเบียนหลังตัด'],
            rows: grouped.map((row) => [term, row.group_code, row.group_name, row.level, row.code, row.name, row.registered_students, row.percent, row.removed, row.registered_students - row.removed]),
        },
    ];
}
