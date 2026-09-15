import assert from 'node:assert/strict';
import test from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import {
    buildRegistrationStatisticsSheets,
    canExportRegistrationStatistics,
    registrationStatisticsFileName,
    registrationStatisticsFilterParameter,
} from '../../resources/js/features/reports/registrationStatisticsExport.ts';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';

const payload = {
    categories: [
        { key: 'target_group', label: 'กลุ่มเป้าหมาย' },
        { key: 'group', label: 'กลุ่มเรียน' },
        { key: 'gender', label: 'เพศ' },
        { key: 'level', label: 'ระดับชั้น' },
        { key: 'occupation', label: 'อาชีพ' },
        { key: 'nationality', label: 'สัญชาติ' },
        { key: 'age', label: 'อายุ' },
    ],
    selected_category: 'target_group',
    selected_category_label: 'กลุ่มเป้าหมาย',
    filter_options: {
        target_group: [{ value: '30', label: 'เด็กออกกลางคัน', count: 3 }],
        group: [{ value: 'เสนา ม.ปลาย B', label: 'เสนา ม.ปลาย B', count: 3 }],
        gender: [{ value: '1', label: 'ชาย', count: 3 }],
        level: [{ value: '3', label: 'มัธยมศึกษาตอนปลาย', count: 3 }],
        occupation: [],
        nationality: [],
        age: [],
    },
    applied_filters: { group: 'เสนา ม.ปลาย B', gender: '1', level: '3' },
    terms: ['2/2568'],
    selected_term: '2/2568',
    summary: { registered_students: 3, category_count: 1, largest_category: null },
    items: [{ key: '30', code: '30', label: 'เด็กออกกลางคัน', count: 3, percentage: 100 }],
};

test('only teacher and admin roles receive the registration statistics export action', () => {
    assert.equal(canExportRegistrationStatistics('teacher'), true);
    assert.equal(canExportRegistrationStatistics('admin'), true);
    assert.equal(canExportRegistrationStatistics('student'), false);
    assert.equal(canExportRegistrationStatistics('super_admin'), false);
});

test('learning group filters use the level-independent group name parameter', () => {
    assert.equal(registrationStatisticsFilterParameter('group'), 'group_name');
    assert.equal(registrationStatisticsFilterParameter('level'), 'level');
});

test('registration statistics workbook contains typed totals and selected conditions', () => {
    const sheets = buildRegistrationStatisticsSheets(payload);

    assert.equal(sheets.length, 2);
    assert.deepEqual(sheets[0].rows[0], ['2/2568', 'กลุ่มเป้าหมาย', 1, '30', 'เด็กออกกลางคัน', 3, 100]);
    assert.deepEqual(sheets[0].rows.at(-1), ['2/2568', 'กลุ่มเป้าหมาย', '', '', 'รวมทั้งหมด', 3, 100]);
    assert.deepEqual(sheets[1].rows, [
        ['ภาคเรียน', '2/2568'],
        ['หัวข้อที่ใช้แยกผล', 'กลุ่มเป้าหมาย'],
        ['กลุ่มเรียน', 'เสนา ม.ปลาย B'],
        ['เพศ', 'ชาย'],
        ['ระดับชั้น', 'มัธยมศึกษาตอนปลาย'],
    ]);
    assert.equal(registrationStatisticsFileName(payload), 'สถิตินักศึกษาลงทะเบียน-2/2568-กลุ่มเป้าหมาย');
});

test('registration statistics workbook exports as a valid XLSX package', () => {
    const files = unzipSync(createExcelFileBytes(buildRegistrationStatisticsSheets(payload)));
    const statisticsXml = strFromU8(files['xl/worksheets/sheet1.xml']);
    const conditionsXml = strFromU8(files['xl/worksheets/sheet2.xml']);

    assert.ok(files['[Content_Types].xml']);
    assert.match(statisticsXml, /เด็กออกกลางคัน/);
    assert.match(statisticsXml, /<c r="F2" s="0"><v>3<\/v><\/c>/);
    assert.match(conditionsXml, /มัธยมศึกษาตอนปลาย/);
});

test('learning group export shows one merged group name without a redundant code', () => {
    const groupPayload = {
        ...payload,
        selected_category: 'group',
        selected_category_label: 'กลุ่มเรียน',
        summary: { registered_students: 5, category_count: 1, largest_category: null },
        items: [{ key: 'ศกร.ระดับตำบลเสนา', code: 'ศกร.ระดับตำบลเสนา', label: 'ศกร.ระดับตำบลเสนา', count: 5, percentage: 100 }],
    };

    assert.deepEqual(buildRegistrationStatisticsSheets(groupPayload)[0].rows[0], [
        '2/2568', 'กลุ่มเรียน', 1, '', 'ศกร.ระดับตำบลเสนา', 5, 100,
    ]);
});
