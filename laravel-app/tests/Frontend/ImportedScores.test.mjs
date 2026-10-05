import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildImportedScoresPath,
    importedAssessmentLabels,
    ITW51_ASSESSMENT_LABELS,
    normalizeAssessmentScores,
} from '../../resources/js/features/learning/importedScores.ts';

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
