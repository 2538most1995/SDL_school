import assert from 'node:assert/strict';
import test from 'node:test';
import {
    auditIssueColorClass,
    buildImportedScoresPath,
    formatAuditIssueText,
    formatAuditScore,
    formatAuditStatusText,
    importedAssessmentLabels,
    isLowImportedMidtermScore,
    ITW51_ASSESSMENT_LABELS,
    normalizeAssessmentScores,
} from '../../resources/js/features/learning/importedScores.ts';
import { retainSelectedFilterOption } from '../../resources/js/lib/filterOptions.ts';

test('grade mismatches are orange while arithmetic mismatches remain red, including mixed rows', () => {
    assert.deepEqual(['assessment_total', 'total_score', 'grade'].map(auditIssueColorClass), [
        'text-rose-800', 'text-rose-800', 'text-orange-700',
    ]);
});

test('midterm warning highlights only finite scores strictly below 40, including a real zero', () => {
    for (const score of [0, 15, 39, 39.99]) {
        assert.equal(isLowImportedMidtermScore(score), true, `score ${score} should be highlighted`);
    }
    for (const score of [40, 40.01, 50, 60, null, undefined, Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY, '39', '']) {
        assert.equal(isLowImportedMidtermScore(score), false, `score ${score} should keep its normal colour`);
    }
});

test('imported score path sends only active filters with the backend field names', () => {
    assert.equal(
        buildImportedScoresPath({ term: '1/2569', level: '3', group: '220001', subjectCode: 'พว31001', search: 'สมชาย' }),
        '/api/v1/learning/scores/imported?term=1%2F2569&level=3&group=220001&subject_code=%E0%B8%9E%E0%B8%A731001&search=%E0%B8%AA%E0%B8%A1%E0%B8%8A%E0%B8%B2%E0%B8%A2',
    );
    assert.equal(
        buildImportedScoresPath({ term: '', level: '', group: '', subjectCode: '', search: '   ' }),
        '/api/v1/learning/scores/imported',
    );
});

test('imported score path can request only rows with inconsistent ITW calculations', () => {
    assert.equal(
        buildImportedScoresPath({ term: '2/2568', level: '', group: '', subjectCode: '', search: '', calculationStatus: 'incorrect', page: 2, perPage: 100 }),
        '/api/v1/learning/scores/imported?term=2%2F2568&calculation_status=incorrect&page=2&per_page=100',
    );
});

test('imported score path can request only rows with midterm scores below 40', () => {
    assert.equal(
        buildImportedScoresPath({ term: '1/2569', level: '2', group: '220001', subjectCode: '', search: '', midtermStatus: 'below_40', page: 1, perPage: 50 }),
        '/api/v1/learning/scores/imported?term=1%2F2569&level=2&group=220001&midterm_status=below_40&per_page=50',
    );
});

test('imported score columns default to confirmed ITW51 assessment labels', () => {
    assert.deepEqual(importedAssessmentLabels(), [
        'คะแนนบันทึกการเรียนรู้',
        'คะแนนบันทึกการฝึกทักษะ',
        'คะแนนรายงาน/รายงานเชิงปฏิบัติการ',
        'คะแนนแบบฝึกหัด',
        'คะแนนแต้มสะสมงาน',
        'คะแนนผลงาน/ชิ้นงาน',
        'คะแนนโครงงาน',
        'คะแนนทดสอบย่อย',
        'คะแนนอื่นๆ',
    ]);
    assert.equal(ITW51_ASSESSMENT_LABELS.length, 9);
});

test('imported score columns always contain nine labelled assessments', () => {
    assert.deepEqual(importedAssessmentLabels(['แบบฝึกหัด', '', 'โครงงาน']).slice(0, 4), [
        'แบบฝึกหัด',
        'คะแนน 2',
        'โครงงาน',
        'คะแนน 4',
    ]);
    assert.equal(importedAssessmentLabels().length, 9);
});

test('imported assessment values are normalized to nine safe numeric cells', () => {
    assert.deepEqual(normalizeAssessmentScores([12, null, Number.NaN, 7.5]).slice(0, 5), [12, null, null, 7.5, null]);
    assert.equal(normalizeAssessmentScores([1, 2]).length, 9);
});

test('selected filters remain visible when the refreshed option set no longer contains them', () => {
    const options = retainSelectedFilterOption(
        [{ value: 'สค32034', label: 'การเงินเพื่อชีวิต' }],
        'พว31001',
        'วิทยาศาสตร์',
    );

    assert.deepEqual(options, [
        { value: 'พว31001', label: 'วิทยาศาสตร์' },
        { value: 'สค32034', label: 'การเงินเพื่อชีวิต' },
    ]);
    // The helper returns a copy; verify that selecting it again preserves values.
    assert.deepEqual(retainSelectedFilterOption(options, 'พว31001'), options);
});

test('calculation audit formats expected and actual score values including non-numeric grades', () => {
    assert.equal(formatAuditScore(4), '4');
    assert.equal(formatAuditScore(3.5), '3.5');
    assert.equal(formatAuditScore('1'), '1');
    assert.equal(formatAuditScore('ข'), 'ข');
    assert.equal(formatAuditScore(null), '-');

    assert.equal(
        formatAuditIssueText({ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 4, actual: 3.5 }),
        'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 4 แต่ ITW เป็น 3.5',
    );
    assert.equal(
        formatAuditIssueText({ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 1, actual: 'ข' }),
        'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 1 แต่ ITW เป็น ข',
    );
    assert.equal(
        formatAuditIssueText({ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 4, actual: 'ข' }),
        'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 4 แต่ ITW เป็น ข',
    );
    assert.equal(
        formatAuditIssueText({ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 0, actual: 'ข' }),
        'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 0 แต่ ITW เป็น ข',
    );
});

test('audit status uses actual missing final scores, not the absent grade label', () => {
    assert.equal(
        formatAuditStatusText({
            final_exam_score: null,
            grade: 'ข',
            calculation_audit: { status: 'not_checkable', issues: [] },
        }),
        'คำนวณตรงกัน',
    );

    assert.equal(
        formatAuditStatusText({
            final_exam_score: null,
            grade: null,
            calculation_audit: { status: 'not_checkable', issues: [] },
        }),
        'ไม่มีคะแนนปลายภาค',
    );

    assert.equal(
        formatAuditStatusText({
            final_exam_score: 30,
            grade: '4',
            calculation_audit: { status: 'correct', issues: [] },
        }),
        'คำนวณตรงกัน',
    );

    assert.equal(
        formatAuditStatusText({
            final_exam_score: 40,
            grade: '3.5',
            calculation_audit: {
                status: 'incorrect',
                issues: [{ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 4, actual: 3.5 }],
            },
        }),
        'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 4 แต่ ITW เป็น 3.5',
    );
});

test('audit status never describes a recorded final score as missing', () => {
    for (const finalScore of [0, 34]) {
        assert.equal(formatAuditStatusText({
            final_exam_score: finalScore,
            grade: 'ข',
            calculation_audit: {
                status: 'incorrect',
                issues: [{ label: 'เกรดไม่ตรงกับคะแนนรวม', expected: 4, actual: 'ข' }],
            },
        }), 'เกรดไม่ตรงกับคะแนนรวม: ควรเป็น 4 แต่ ITW เป็น ข');
        assert.equal(formatAuditStatusText({
            final_exam_score: finalScore,
            grade: 'ข',
            calculation_audit: { status: 'not_checkable', issues: [] },
        }), 'ข้อมูลไม่ครบสำหรับตรวจ');
    }
});

test('audit status explains transfers and special grades separately from missing final exams', () => {
    assert.equal(formatAuditStatusText({
        final_exam_score: null,
        grade: 'ข',
        calculation_audit: { status: 'not_checkable', reason: 'transferred', issues: [] },
    }), 'วิชาเทียบโอน — ไม่เทียบเกรดจากคะแนนรวม');
    for (const grade of ['ม', 'มส', 'ผ', 'มผ', 'ร']) {
        assert.equal(formatAuditStatusText({
            final_exam_score: 34,
            grade,
            calculation_audit: { status: 'not_checkable', reason: 'special_grade', issues: [] },
        }), `เกรดพิเศษ ${grade} — ไม่เทียบเกรดจากคะแนนรวม`);
    }
});
