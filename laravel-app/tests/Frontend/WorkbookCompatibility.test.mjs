import assert from 'node:assert/strict';
import test from 'node:test';
import * as XLSX from 'xlsx';
import { subjectCodesForRecord } from '../../resources/js/features/nnet/recordHelpers.ts';

test('upgraded workbook parser preserves Thai N-NET headers, identity text and scores in XLSX', () => {
    const codes = subjectCodesForRecord(2);
    const rows = [
        ['ลำดับ', 'เลขที่นั่งสอบ', 'เลขประจำตัวประชาชน', 'ชื่อ - สกุล', 'คะแนนรวม', ...codes],
        [1, '0000123', '0123456789012', 'ทดสอบ นักศึกษา', 250, 40, 45, 50, 55, 60],
    ];
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(rows), 'ผลสอบภาษาไทย');
    const bytes = XLSX.write(workbook, { type: 'buffer', bookType: 'xlsx' });
    const restored = XLSX.read(bytes, { type: 'buffer' });
    assert.deepEqual(XLSX.utils.sheet_to_json(restored.Sheets[restored.SheetNames[0]], { header: 1, raw: true }), rows);
});

test('Thai CSV workbook exchange preserves leading-zero identifiers as text', () => {
    const rows = [['ชื่อ', 'รหัสนักศึกษา', 'เลขประจำตัวประชาชน', '211'], ['ทดสอบ นักศึกษา', '0000000001', '0123456789012', '0']];
    const csv = XLSX.utils.sheet_to_csv(XLSX.utils.aoa_to_sheet(rows));
    const workbook = XLSX.read(csv, { type: 'string', raw: true });
    assert.deepEqual(XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { header: 1, raw: true }), rows);
});
