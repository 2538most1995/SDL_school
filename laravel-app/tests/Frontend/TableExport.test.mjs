import assert from 'node:assert/strict';
import test from 'node:test';
import * as XLSX from 'xlsx';
import { buildTableExportSheet, studentNameExportFields, studentGroupExportFields } from '../../resources/js/lib/tableExport.ts';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';

test('student export separates code, name, level and group while preserving displayed masked values', () => {
    const student = { code: '0000000001', name: 'ทดสอบ นักศึกษา', level: 'มัธยมศึกษาตอนต้น', group: 'กลุ่ม ก', groupCode: '000123', citizen: 'private-not-displayed' };
    const sheet = buildTableExportSheet('นักศึกษา', [
        { header: 'นักศึกษา', fields: studentNameExportFields((row) => row.code, (row) => row.name) },
        { header: 'เลขบัตรประชาชน' },
        { header: 'ระดับ / กลุ่มเรียน', fields: studentGroupExportFields((row) => row.level, (row) => row.group, (row) => row.groupCode) },
    ], [{ original: student, displayed: ['ทดสอบ นักศึกษา0000000001', 'x-xxxx-xxxxx-xx-x', 'กลุ่ม กมัธยมศึกษาตอนต้น000123'] }]);
    assert.deepEqual(sheet.columns, ['รหัสนักศึกษา', 'ชื่อ - สกุล', 'เลขบัตรประชาชน', 'ระดับชั้น', 'กลุ่มเรียน', 'รหัสกลุ่มเรียน']);
    assert.deepEqual(sheet.rows[0], ['0000000001', 'ทดสอบ นักศึกษา', 'x-xxxx-xxxxx-xx-x', 'มัธยมศึกษาตอนต้น', 'กลุ่ม ก', '000123']);
    assert.equal(JSON.stringify(sheet).includes(student.citizen), false);
    const bytes = createExcelFileBytes([sheet]);
    const workbook = XLSX.read(bytes, { type: 'array' });
    const values = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { header: 1 });
    assert.deepEqual(values, [sheet.columns, ...sheet.rows]);
});

test('export follows only supplied current-page row order and retains formatted non-student cells', () => {
    const sheet = buildTableExportSheet('หน้า 2', [
        { header: 'นักศึกษา', fields: studentNameExportFields((row) => row.code, (row) => row.name) },
        { header: 'ผลประเมิน' },
    ], [
        { original: { code: '02', name: '=SUM(A1:A2)' }, displayed: ['merged', 'ผ่านเกณฑ์'] },
        { original: { code: '01', name: 'ทดสอบ' }, displayed: ['merged', 'รอผล'] },
    ]);
    assert.deepEqual(sheet.rows, [['02', '=SUM(A1:A2)', 'ผ่านเกณฑ์'], ['01', 'ทดสอบ', 'รอผล']]);
    const workbook = XLSX.read(createExcelFileBytes([sheet]), { type: 'array' });
    const ws = workbook.Sheets[workbook.SheetNames[0]];
    assert.equal(ws.B2.t, 's');
    assert.equal(ws.B2.f, undefined);
});
