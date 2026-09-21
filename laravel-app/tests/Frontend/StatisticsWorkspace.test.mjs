import assert from 'node:assert/strict';
import test from 'node:test';
import { strFromU8, unzipSync } from 'fflate';
import { createExcelFileBytes } from '../../resources/js/lib/excel.ts';
import {
    buildStatisticsWorkspaceSheets,
    categoriesForOrientation,
    createDefaultAxisConfiguration,
    genericPayloadToStatisticRows,
    registrationPayloadsToStatisticRows,
    reportById,
    statisticCategories,
    statisticReports,
    summarizeStatisticRows,
} from '../../resources/js/features/reports/statisticsWorkspace.ts';

test('statistics workspace exposes all 15 requested report choices without inventing unsupported sources', () => {
    assert.equal(statisticReports.length, 15);
    assert.equal(reportById(1).source, 'new-students');
    assert.equal(reportById(3).source, 'registration-statistics');
    assert.equal(reportById(6).source, null);
    assert.match(reportById(6).unavailableReason, /หมดสภาพ/);
    assert.equal(reportById(15).source, 'transfers');
});

test('report format catalog keeps the 10 requested dimensions and marks missing source fields', () => {
    assert.equal(statisticCategories.length, 10);
    assert.deepEqual(statisticCategories.map((item) => item.label), [
        'ระดับชั้น', 'รหัสกลุ่ม', 'เพศ', 'อายุ', 'วิธีเรียน', 'อาชีพ', 'กลุ่มเป้าหมาย', 'สัญชาติ', 'รหัสความพิการ', 'จุดการศึกษา',
    ]);
    assert.equal(statisticCategories.find((item) => item.key === 'learning_method').supported, false);
    assert.equal(statisticCategories.find((item) => item.key === 'disability').supported, false);
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
    const files = unzipSync(createExcelFileBytes(buildStatisticsWorkspaceSheets(reportById(3), '1/2569', 'อำเภอเสนา', rows, {
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
