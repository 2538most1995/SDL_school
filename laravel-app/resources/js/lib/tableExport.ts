import type { ExcelCell, ExcelSheet } from './excel';

export type TableExportField<T> = { header: string; value: (row: T) => ExcelCell };
export type TableExportColumn<T> = { header: string; fields?: TableExportField<T>[] };

/** Expand explicitly declared data fields, retaining rendered values for other columns. */
export function buildTableExportSheet<T>(name: string, columns: TableExportColumn<T>[], rows: { original: T; displayed: string[] }[]): ExcelSheet {
    return {
        name,
        columns: columns.flatMap((column) => column.fields?.map((field) => field.header) ?? [column.header]),
        rows: rows.map((row) => columns.flatMap((column, index) => column.fields
            ? column.fields.map((field) => field.value(row.original))
            : [row.displayed[index] ?? ''])),
    };
}

export function studentNameExportFields<T>(code: (row: T) => string, name: (row: T) => string): TableExportField<T>[] {
    return [{ header: 'รหัสนักศึกษา', value: code }, { header: 'ชื่อ - สกุล', value: name }];
}

export function studentGroupExportFields<T>(level: (row: T) => string, group: (row: T) => string, code?: (row: T) => string): TableExportField<T>[] {
    return [
        { header: 'ระดับชั้น', value: level },
        { header: 'กลุ่มเรียน', value: group },
        ...(code ? [{ header: 'รหัสกลุ่มเรียน', value: code }] : []),
    ];
}
