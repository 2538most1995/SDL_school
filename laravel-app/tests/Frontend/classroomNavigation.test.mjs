import test from 'node:test';
import assert from 'node:assert/strict';
import { legacyClassroomLocation, classroomUnitParams } from '../../resources/js/features/learning/classroom/navigation.ts';

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


test('course activity navigation clears previous content, task and library state while retaining the course', () => {
    const next = classroomUnitParams('?subject_code=MATH&education_level=3&unit_id=7&activity_id=50&assignment_id=2&task_id=9&section=worksheet&mode=files&materials=all', '8');
    assert.deepEqual(Object.fromEntries(next), { subject_code: 'MATH', education_level: '3', unit_id: '8' });
    assert.deepEqual(Object.fromEntries(classroomUnitParams(next.toString(), '')), { subject_code: 'MATH', education_level: '3' });
});

test('legacy content remains an explicit activity choice and invalid activity selectors are rejected', () => {
    assert.equal(classroomUnitParams('?subject_code=MATH&education_level=3', 'legacy').get('unit_id'), 'legacy');
    for (const id of ['0', '-1', '1/2', 'other', 'https://example.test']) assert.throws(() => classroomUnitParams('', id));
});
