import assert from 'node:assert/strict';
import test from 'node:test';
import * as XLSX from 'xlsx';
import { buildRegisteredSubjectsSheets, reducedRegistrationGroups, registrationReduction } from '../../resources/js/features/reports/registeredSubjectsExport.ts';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';

const subjects = [
    { id: '1|A', primary: 'ภาษาไทย', secondary: 'A', group: 'ประถมศึกษา', registeredCount: 119 },
    { id: '2|A', primary: 'ภาษาไทย', secondary: 'A', group: 'มัธยมศึกษาตอนต้น', registeredCount: 3 },
];
const groups = [
    { subject_id: '1|A', code: 'A', name: 'ภาษาไทย', level: 'ประถมศึกษา', group_code: '0002', group_name: 'กลุ่มเดียวกัน', registered_students: 60 },
    { subject_id: '1|A', code: 'A', name: 'ภาษาไทย', level: 'ประถมศึกษา', group_code: '0001', group_name: 'กลุ่มเดียวกัน', registered_students: 59 },
    { subject_id: '2|A', code: 'A', name: 'ภาษาไทย', level: 'มัธยมศึกษาตอนต้น', group_code: '0003', group_name: 'กลุ่มอื่น', registered_students: 3 },
];

test('only selected subject and level are reduced; originals stay unchanged and repeat calculations do not compound', () => {
    const sheets = buildRegisteredSubjectsSheets('subject', subjects, groups, { '1|A': 5 }, '1/2569');
    assert.deepEqual(sheets[0].rows[0], ['1/2569', 'A', 'ภาษาไทย', 'ประถมศึกษา', 119, 5, 6, 113]);
    assert.deepEqual(sheets[0].rows[1].slice(-4), [3, 0, 0, 3]);
    assert.equal(sheets[1].rows.filter(row => row[3] === 'ประถมศึกษา').reduce((sum, row) => sum + row[9], 0), 113);
    assert.deepEqual(sheets, buildRegisteredSubjectsSheets('subject', subjects, groups, { '1|A': 5 }, '1/2569'));
    assert.equal(subjects[0].registeredCount, 119);
    assert.deepEqual(groups.map(row => row.registered_students), [60, 59, 3]);
});

test('small and fractional reductions reconcile to the subject total without negative group counts', () => {
    for (const percent of [0, 0.1, 5, 12.5, 50, 99.9, 100]) {
        for (let count = 0; count < 40; count++) {
            const source = groups.slice(0, 2).map((row, index) => ({ ...row, registered_students: index === 0 ? count : 1 }));
            const reduced = reducedRegistrationGroups(source, { '1|A': percent });
            assert.equal(reduced.reduce((sum, row) => sum + row.removed, 0), registrationReduction(count + 1, percent));
            assert.ok(reduced.every(row => row.removed >= 0 && row.removed <= row.registered_students));
        }
    }
    for (const value of [-1, 101, NaN, Infinity]) assert.throws(() => registrationReduction(119, value));
});

test('filtered export excludes other subjects and preserves group codes and numeric counts in real XLSX', () => {
    const sheets = buildRegisteredSubjectsSheets('subject', subjects.slice(0, 1), groups, { '1|A': 5 }, '2/2568');
    assert.equal(sheets[1].rows.length, 2);
    const workbook = XLSX.read(createExcelFileBytes(sheets), { type: 'array' });
    assert.deepEqual(workbook.SheetNames, ['รายวิชา', 'แยกกลุ่มเรียน']);
    const sheet = workbook.Sheets['แยกกลุ่มเรียน'];
    assert.equal(sheet.B2.v, '0001');
    assert.equal(sheet.B2.t, 's');
    assert.equal(sheet.G2.t, 'n');
    assert.equal(sheet.J2.v + sheet.J3.v, workbook.Sheets['รายวิชา'].H2.v);
});

test('student export includes all filtered pages and groups actual registrations without dropping any person', () => {
    const students = Array.from({ length: 31 }, (_, index) => ({ id: String(index), secondary: String(index).padStart(10, '0'), primary: index === 0 ? '=SUM(A1)' : 'ชื่อทดสอบ', group: 'ระดับ · กลุ่ม', level: 'ประถมศึกษา', group_label: 'กลุ่มทดสอบ', group_code: index % 2 ? '0001' : '0002', registeredCount: 5 }));
    const sheets = buildRegisteredSubjectsSheets('student', students, [], { '1|A': 100 }, '1/2569');
    assert.equal(sheets.length, 2);
    assert.equal(sheets[0].rows.length, 31);
    assert.equal(sheets[1].rows.length, 31);
    assert.equal(sheets[1].rows[0][5], '0001');
    assert.equal(sheets[1].rows.reduce((sum, row) => sum + row[6], 0), 155);
    const workbook = XLSX.read(createExcelFileBytes(sheets), { type: 'array' });
    assert.equal(workbook.Sheets['รายคน'].C2.v, '=SUM(A1)');
    assert.equal(workbook.Sheets['รายคน'].C2.f, undefined);
    assert.deepEqual(buildRegisteredSubjectsSheets('subject', [], [], {}, '').map(sheet => sheet.rows), [[], []]);
});
