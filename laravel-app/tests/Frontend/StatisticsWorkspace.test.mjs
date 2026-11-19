import assert from 'node:assert/strict';
import test from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';
import {
    buildStatisticsCrossTabSheets,
    buildStatisticsWorkspaceSheets,
    categoriesForReport,
    configurationForReportResult,
    categoriesForOrientation,
    createDefaultAxisConfiguration,
    genericPayloadToCrossTab,
    genericPayloadToStatisticRows,
    normalizeAxisConfiguration,
    registrationPayloadsToStatisticRows,
    reportById,
    registrationStudentsToGeneric,
    uniqueStatisticStudents,
    statisticCategories,
    statisticReports,
    summarizeStatisticRows,
} from '../../resources/js/features/reports/statisticsWorkspace.ts';

test('statistics workspace exposes only reports backed by a real source', () => {
    assert.equal(statisticReports.length, 10);
    assert.equal(reportById(1).source, 'new-students');
    assert.equal(reportById(2).source, 'registration-statistics');
    assert.equal(reportById(3).source, 'graduates');
    assert.equal(reportById(4).source, 'expected-graduates');
    assert.equal(reportById(5).source, 'transfers');
    assert.ok(statisticReports.every((report) => report.source));
});

test('configured dimensions become real nested rows and columns in one cross-tab', () => {
    const crossTab = genericPayloadToCrossTab({
        total: 4,
        active: 4,
        groups: 2,
        selected_term: '1/2569',
        rows: [
            { id: '1', primary: 'ก', secondary: '1', group: 'ประถมศึกษา · กลุ่ม A', metric: '' },
            { id: '2', primary: 'ข', secondary: '2', group: 'ประถมศึกษา · กลุ่ม A', metric: '' },
            { id: '3', primary: 'ค', secondary: '3', group: 'มัธยมศึกษาตอนต้น · กลุ่ม B', metric: '' },
            { id: '4', primary: 'ง', secondary: '4', group: 'มัธยมศึกษาตอนต้น · กลุ่ม B', metric: '' },
        ],
    }, { vertical: ['level', 'group'], horizontal: ['group'] });

    assert.deepEqual(crossTab.row_categories.map((item) => item.key), ['level', 'group']);
    assert.deepEqual(crossTab.column_categories.map((item) => item.key), ['group']);
    assert.equal(crossTab.summary.registered_students, 4);
    assert.equal(crossTab.summary.row_count, 2);
    assert.equal(crossTab.summary.column_count, 2);
    assert.equal(crossTab.rows[0].parts.length, 2);
    assert.equal(Object.values(crossTab.rows[0].cells).reduce((sum, value) => sum + value, 0), crossTab.rows[0].total);

    const files = unzipSync(createExcelFileBytes(buildStatisticsCrossTabSheets(reportById(1), '1/2569', 'อำเภอเสนา', crossTab)));
    const reportXml = strFromU8(files['xl/worksheets/sheet1.xml']);
    const conditionXml = strFromU8(files['xl/worksheets/sheet2.xml']);
    assert.match(reportXml, /แกนตั้ง/);
    assert.match(reportXml, /รวมคอลัมน์/);
    assert.match(reportXml, /กลุ่ม A/);
    assert.match(conditionXml, /แกนนอน/);
});

test('report format catalog keeps only dimensions backed by imported source fields', () => {
    assert.equal(statisticCategories.length, 12);
    assert.deepEqual(statisticCategories.map((item) => item.label), [
        'ระดับชั้น', 'กลุ่มเรียน', 'เพศ', 'อายุ', 'อาชีพ', 'กลุ่มเป้าหมาย', 'สัญชาติ', 'สถานะ N-Net / E-Exam', 'ช่วงอายุ', 'เงื่อนไขที่ยังขาด', 'พบกลุ่ม / วิธีเรียน', 'รายวิชา',
    ]);
    assert.deepEqual(categoriesForReport(reportById(2)).map((item) => item.key), [
        'level', 'group', 'gender', 'age', 'occupation', 'target_group', 'nationality', 'learning_method', 'subject',
    ]);
    assert.deepEqual(categoriesForReport(reportById(3)).map((item) => item.key), ['level', 'group', 'gender', 'target_group', 'learning_method', 'subject']);
    assert.deepEqual(categoriesForReport(reportById(4)).map((item) => item.key), ['level', 'group', 'gender', 'target_group', 'nnet', 'learning_method', 'subject']);
    assert.deepEqual(normalizeAxisConfiguration(reportById(3), {
        vertical: ['gender', 'level'],
        horizontal: ['age'],
    }), { vertical: ['gender', 'level'], horizontal: ['level'] });
});

test('vertical and horizontal category configurations remain independent', () => {
    const configuration = createDefaultAxisConfiguration();
    configuration.vertical.push('gender');

    assert.deepEqual(categoriesForOrientation(configuration, 'vertical'), ['group', 'gender']);
    assert.deepEqual(categoriesForOrientation(configuration, 'horizontal'), ['level']);

    const horizontal = categoriesForOrientation(configuration, 'horizontal');
    horizontal.push('age');
    assert.deepEqual(configuration.horizontal, ['level']);
});

test('generic student lists are aggregated by real level and group values', () => {
    const rows = genericPayloadToStatisticRows({
        total: 3,
        active: 3,
        groups: 2,
        selected_term: '1/2569',
        rows: [
            { id: '1', primary: 'ก', secondary: '1', group: 'ประถมศึกษา · กลุ่ม A', metric: 'ภาคเรียน 1/2569' },
            { id: '2', primary: 'ข', secondary: '2', group: 'ประถมศึกษา · กลุ่ม A', metric: 'ภาคเรียน 1/2569' },
            { id: '3', primary: 'ค', secondary: '3', group: 'มัธยมศึกษาตอนต้น · กลุ่ม B', metric: 'ภาคเรียน 1/2569' },
        ],
    }, ['level', 'group']);

    assert.deepEqual(rows.slice(0, 2).map((row) => [row.label, row.count, row.percentage]), [
        ['ประถมศึกษา', 2, 66.7],
        ['มัธยมศึกษาตอนต้น', 1, 33.3],
    ]);
    assert.equal(rows.find((row) => row.label === 'กลุ่ม A').count, 2);
});

test('transfer rows use explicit level and group fields and count each student once', () => {
    const crossTab = genericPayloadToCrossTab({
        total: 3,
        active: 3,
        groups: 2,
        rows: [
            { id: 'a-1', entity_key: 'a', primary: 'วิชา 1', secondary: 'ทช11001', group: 'ชื่อ ก · 001', level: 'ประถมศึกษา', group_code: 'A', group_label: 'กลุ่ม A', metric: '' },
            { id: 'a-2', entity_key: 'a', primary: 'วิชา 2', secondary: 'ทช11002', group: 'ชื่อ ก · 001', level: 'ประถมศึกษา', group_code: 'A', group_label: 'กลุ่ม A', metric: '' },
            { id: 'b-1', entity_key: 'b', primary: 'วิชา 1', secondary: 'ทช21001', group: 'ชื่อ ข · 002', level: 'มัธยมศึกษาตอนต้น', group_code: 'B', group_label: 'กลุ่ม B', metric: '' },
        ],
    }, { vertical: ['level'], horizontal: ['group'] });

    assert.equal(crossTab.summary.registered_students, 2);
    assert.equal(crossTab.rows.find((row) => row.label === 'ประถมศึกษา').total, 1);
    assert.deepEqual(crossTab.columns.map((column) => column.label), ['กลุ่ม A', 'กลุ่ม B']);
});

test('registration dimensions remain separate and export as a valid workbook', () => {
    const base = {
        categories: [], filter_options: {}, applied_filters: {}, terms: ['1/2569'], selected_term: '1/2569',
        summary: { registered_students: 4, category_count: 1, largest_category: null },
    };
    const rows = registrationPayloadsToStatisticRows([
        { ...base, selected_category: 'level', selected_category_label: 'ระดับชั้น', items: [{ key: '1', code: '1', label: 'ประถมศึกษา', count: 4, percentage: 100 }] },
        { ...base, selected_category: 'gender', selected_category_label: 'เพศ', items: [{ key: '2', code: '2', label: 'หญิง', count: 4, percentage: 100 }] },
    ]);
    const summary = summarizeStatisticRows(rows, 4);
    const files = unzipSync(createExcelFileBytes(buildStatisticsWorkspaceSheets(reportById(2), '1/2569', 'อำเภอเสนา', rows, {
        sourceTotal: summary.sourceTotal,
        orientation: 'horizontal',
        categoryLabels: ['ระดับชั้น', 'เพศ'],
    })));
    const reportXml = strFromU8(files['xl/worksheets/sheet1.xml']);
    const conditionXml = strFromU8(files['xl/worksheets/sheet2.xml']);

    assert.equal(rows.length, 2);
    assert.equal(summary.sourceTotal, 4);
    assert.equal(summary.classificationCount, 2);
    assert.equal(summary.categoryCount, 2);
    assert.match(reportXml, /ประถมศึกษา/);
    assert.match(reportXml, /หญิง/);
    assert.match(reportXml, /รวมทั้งหมด/);
    assert.match(conditionXml, /อำเภอเสนา/);
    assert.match(conditionXml, /แนวนอน/);
    assert.match(conditionXml, /รวมผู้เรียนทั้งหมด/);
});


test('new reports preserve group/level defaults and expose real breakdowns in tables and Excel', () => {
    assert.deepEqual(createDefaultAxisConfiguration(), { vertical: ['group'], horizontal: ['level'] });
    for (const [id, field, value] of [[6, 'gender', 'หญิง'], [7, 'age_band', '16-24 ปี'], [9, 'nnet', 'ยังไม่ได้สอบ'], [10, 'completion_status', 'ขาด กพช.']]) {
        const report = reportById(id);
        const config = configurationForReportResult(report, createDefaultAxisConfiguration());
        assert.deepEqual(config.vertical, ['group']);
        assert.deepEqual(config.horizontal, ['level', field]);
        const crossTab = genericPayloadToCrossTab({ total: 1, active: 1, groups: 1, rows: [
            { id: '1', primary: 'ทดสอบ', secondary: '1', group: '', level: 'ประถมศึกษา', group_label: 'กลุ่ม A', metric: value, [field]: value },
        ] }, config);
        assert.equal(crossTab.summary.registered_students, 1);
        assert.match(crossTab.columns[0].label, new RegExp(value));
        assert.equal(crossTab.rows[0].studentsByCell[crossTab.columns[0].key][0][field], value);
        const files = unzipSync(createExcelFileBytes(buildStatisticsCrossTabSheets(report, '1/2569', 'เสนา', crossTab)));
        assert.ok(strFromU8(files['xl/worksheets/sheet1.xml']).includes(value));
    }
});


test('registration dimensions preserve subject/method correlation and unique student totals', () => {
    const payload = { total: 2, active: 2, groups: 1, rows: [
        { id: '1|same-code', student_id: 'same-code', primary: 'A', secondary: 'same-code', group: 'Group A', level: 'Primary', registration_dimensions: [
            { subject_code: 'C', subject_name: 'Core', learning_method: 'พบกลุ่ม' },
            { subject_code: 'E', subject_name: 'Elective', learning_method: 'ทางไกล' },
            { subject_code: 'E', subject_name: 'Elective', learning_method: 'ทางไกล' },
        ] },
        { id: '2|same-code', student_id: 'same-code', primary: 'B', secondary: 'same-code', group: 'Group A', level: 'Secondary', registration_dimensions: [
            { subject_code: 'C', subject_name: 'Core', learning_method: 'ทางไกล' },
        ] },
    ] };
    const crossTab = genericPayloadToCrossTab(payload, { vertical: ['group'], horizontal: ['subject', 'learning_method'] });
    assert.equal(crossTab.summary.registered_students, 2);
    assert.equal(crossTab.rows[0].total, 2);
    assert.equal(crossTab.columns.length, 3);
    assert.ok(!crossTab.columns.some((col) => col.label === 'E Elective · พบกลุ่ม'));
    assert.equal(Object.values(crossTab.rows[0].cells).reduce((a,b) => a+b, 0), 3);
    assert.equal(uniqueStatisticStudents(Object.values(crossTab.rows[0].studentsByCell).flat()).length, 2);
    const bySubject = genericPayloadToCrossTab(payload, { vertical: ['subject'], horizontal: ['group'] });
    assert.equal(bySubject.columns[0].total, 2);
    assert.equal(bySubject.rows.find((row) => row.label === 'C Core').total, 2);
    assert.equal(bySubject.rows.find((row) => row.label === 'E Elective').total, 1);
    const files = unzipSync(createExcelFileBytes(buildStatisticsCrossTabSheets(reportById(7), '1/2569', 'Test', crossTab)));
    assert.ok(strFromU8(files['xl/worksheets/sheet1.xml']).includes('E Elective'));
    assert.ok(strFromU8(files['xl/worksheets/sheet2.xml']).includes('นับผู้เรียนไม่ซ้ำ'));
});

test('registration student adapter keeps same code in different levels and missing registrations explicit', () => {
    const student = { code: 'same', full_name: 'Synthetic', level: { id: 1, label: 'Primary' }, group: { code: 'G1', name: 'A' } };
    const payload = registrationStudentsToGeneric({ total: 2, active: 2, groups: 1, rows: [
        { student, gender: 'ชาย', age: '20 ปี', occupation: 'รับจ้าง', nationality: 'ไทย', target_group: 'อื่นๆ', registration_dimensions: [] },
        { student: { ...student, level: { id: 2, label: 'Secondary' } }, gender: 'หญิง', age: '25 ปี', occupation: '', nationality: '', target_group: '', registration_dimensions: [] },
    ] });
    assert.notEqual(payload.rows[0].id, payload.rows[1].id);
    const crossTab = genericPayloadToCrossTab(payload, { vertical: ['group'], horizontal: ['subject'] });
    assert.equal(crossTab.summary.registered_students, 2);
    assert.equal(crossTab.columns[0].label, 'ไม่มีวิชาลงทะเบียนในภาคเรียนนี้');
    assert.equal(uniqueStatisticStudents(payload.rows).length, 2);
    assert.equal(genericPayloadToCrossTab(payload, { vertical: ['group'], horizontal: ['age'] }).columns[0].label, '20 ปี');
});
