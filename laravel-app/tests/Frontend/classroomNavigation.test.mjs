import test from 'node:test';
import assert from 'node:assert/strict';
import { legacyClassroomLocation } from '../../resources/js/features/learning/classroom/navigation.ts';

test('old assignment links open file worksheets and keep the selected assignment and course', () => {
    const result = new URL(legacyClassroomLocation('assignments', '?assignment_id=42&subject_code=พท31001&education_level=3'), 'https://school.test');
    assert.equal(result.pathname, '/learning/classroom');
    assert.deepEqual(Object.fromEntries(result.searchParams), { section: 'worksheet', mode: 'files', assignment_id: '42', subject_code: 'พท31001', education_level: '3' });
});

test('old media links open the shared classroom library without accepting arbitrary destinations', () => {
    const result = new URL(legacyClassroomLocation('resources', '?course=ทั้งหมด&redirect=https://other.test&assignment_id=1'), 'https://school.test');
    assert.deepEqual(Object.fromEntries(result.searchParams), { materials: 'all' });
    assert.equal(new URL(legacyClassroomLocation('assignments', '?assignment_id=-1&education_level=9'), 'https://school.test').searchParams.has('assignment_id'), false);
});
