import test from 'node:test';
import assert from 'node:assert/strict';
import { lessonPlanFilePath } from '../../resources/js/features/learning/lesson-plans/fileUrls.ts';

test('native readers and downloads retain district context and PDF version without a Blob wait', () => {
    const current = '/api/v1/learning/lesson-plans/8/file?v=abc123';
    assert.equal(lessonPlanFilePath('8', current, '2'), '/api/v1/learning/lesson-plans/8/file?v=abc123&district_id=2');
    assert.equal(lessonPlanFilePath('8', current, '2', true), '/api/v1/learning/lesson-plans/8/file?v=abc123&district_id=2&download=1');
    assert.equal(lessonPlanFilePath('8', current, ''), '/api/v1/learning/lesson-plans/8/file?v=abc123');
});

test('file navigation always uses the authenticated fixed route rather than an incoming URL origin', () => {
    assert.equal(lessonPlanFilePath('8', 'https://other.example/file.pdf?v=new', '2'), '/api/v1/learning/lesson-plans/8/file?v=new&district_id=2');
    assert.equal(lessonPlanFilePath('8/../4', '', ''), '/api/v1/learning/lesson-plans/8%2F..%2F4/file');
});
