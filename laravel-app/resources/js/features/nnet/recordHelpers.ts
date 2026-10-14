export function subjectCodesForRecord(level: number, importedCodes?: string[] | null): string[] {
    if (importedCodes?.length) return [...importedCodes];
    const prefix = level === 1 ? '1' : level === 2 ? '2' : '4';
    return Array.from({ length: 5 }, (_, index) => `${prefix}1${index + 1}`);
}

export type RecordPage<T> = { items: T[]; total: number; current_page: number; last_page: number; page_size: number };

export async function collectRecordPages<T extends { id: number }>(loadPage: (page: number) => Promise<RecordPage<T>>): Promise<T[]> {
    const first = await loadPage(1);
    if (first.current_page !== 1 || first.last_page < 1) throw new Error('ข้อมูลแบ่งหน้าไม่สมบูรณ์ กรุณาลองใหม่');
    const records = [...first.items];
    for (let page = 2; page <= first.last_page; page += 1) {
        const next = await loadPage(page);
        if (next.total !== first.total || next.last_page !== first.last_page || next.current_page !== page) {
            throw new Error('ข้อมูลเปลี่ยนระหว่างส่งออก กรุณาลองใหม่');
        }
        records.push(...next.items);
    }
    if (records.length !== first.total || new Set(records.map((record) => record.id)).size !== first.total) {
        throw new Error('ข้อมูลส่งออกไม่ครบหรือมีรายการซ้ำ กรุณาลองใหม่');
    }
    return records;
}
