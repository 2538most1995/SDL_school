import assert from 'node:assert/strict';
import test from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';
import {
    buildStatisticsCrossTabSheets,
    buildStatisticsWorkspaceSheets,
    categoriesForReport,
    categoriesForOrientation,
    createDefaultAxisConfiguration,
    genericPayloadToCrossTab,
    genericPayloadToStatisticRows,
    normalizeAxisConfiguration,
    registrationPayloadsToStatisticRows,
    reportById,
    statisticCategories,
    statisticReports,
    summarizeStatisticRows,
} from '../../resources/js/features/reports/statisticsWorkspace.ts';

test('statistics workspace exposes only reports backed by a real source', () => {
    assert.equal(statisticReports.length, 5);
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
    assert.equal(statisticCategories.length, 8);
    assert.deepEqual(statisticCategories.map((item) => item.label), [
        'ระดับชั้น', 'กลุ่มเรียน', 'เพศ', 'อายุ', 'อาชีพ', 'กลุ่มเป้าหมาย', 'สัญชาติ', 'สถานะ N-Net / E-Exam',
    ]);
    assert.deepEqual(categoriesForReport(reportById(2)).map((item) => item.key), [
        'level', 'group', 'gender', 'age', 'occupation', 'target_group', 'nationality', 'nnet',
    ]);
    assert.deepEqual(categoriesForReport(reportById(3)).map((item) => item.key), ['level', 'group']);
    assert.deepEqual(normalizeAxisConfiguration(reportById(3), {
        vertical: ['gender', 'level'],
        horizontal: ['age'],
    }), { vertical: ['level'], horizontal: ['group'] });
});

test('vertical and horizontal category configurations remain independent', () => {
    const configuration = createDefaultAxisConfiguration();
    configuration.vertical.push('gender');

    assert.deepEqual(categoriesForOrientation(configuration, 'vertical'), ['level', 'gender']);
    assert.deepEqual(categoriesForOrientation(configuration, 'horizontal'), ['group']);

    const horizontal = categoriesForOrientation(configuration, 'horizontal');
    horizontal.push('age');
    assert.deepEqual(configuration.horizontal, ['group']);
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
