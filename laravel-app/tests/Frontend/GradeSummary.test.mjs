import assert from 'node:assert/strict';
import test from 'node:test';
import { summarizeGradeRows, summarizeGradesByTerm } from '../../resources/js/features/students/gradeSummary.ts';

const rows = [
    { term: '1/2568', credits: 3, type: 'compulsory', grade: '4' },
    { term: '1/2568', credits: 2, type: 'elective', grade: '2' },
    { term: '2/2568', credits: 4, type: 'compulsory', grade: '3' },
    { term: '2/2568', credits: 2, type: 'elective', grade: '0' },
];

test('calculates a separate GPA for each academic term', () => {
    const summaries = summarizeGradesByTerm(rows);

    assert.equal(summaries.find((item) => item.term === '1/2568')?.gpa, 3.2);
    assert.equal(summaries.find((item) => item.term === '2/2568')?.gpa, 3);
});

test('cumulative GPAX remains separate from a selected term GPA', () => {
    assert.equal(summarizeGradeRows(rows).gpa, 3.11);
    assert.equal(summarizeGradeRows(rows.filter((row) => row.term === '1/2568')).gpa, 3.2);
});
