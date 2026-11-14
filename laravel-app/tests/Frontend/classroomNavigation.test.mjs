import test from 'node:test';
import assert from 'node:assert/strict';
import { legacyClassroomLocation, classroomUnitParams, classroomTabParams, classroomKindParams, classroomAssignmentParams, classroomContentPage, classroomTaskItemParams } from '../../resources/js/features/learning/classroom/navigation.ts';

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

test('tabs retain course and chapter scope but clear selected work and stale filters', () => {
    const search = '?subject_code=MATH&education_level=3&unit_id=8&activity_id=50&assignment_id=2&task_id=9&section=worksheet&mode=files';
    assert.deepEqual(Object.fromEntries(classroomTabParams(search, 'documents')), { subject_code: 'MATH', education_level: '3', unit_id: '8', tab: 'documents' });
    assert.equal(classroomTabParams(search, 'library').get('materials'), 'all');
    assert.equal(classroomTabParams('?materials=all&tab=tasks', 'lessons').toString(), '');
    assert.equal(classroomUnitParams('?subject_code=MATH&tab=documents', 'legacy').has('tab'), false);
});

test('content type and file worksheet mode are encoded in the URL for browser back', () => {
    const search = '?subject_code=MATH&education_level=3&unit_id=8&activity_id=50&tab=documents&materials=all';
    const files = classroomKindParams(search, 'worksheet', 'files');
    assert.deepEqual(Object.fromEntries(files), { subject_code: 'MATH', education_level: '3', unit_id: '8', section: 'worksheet', mode: 'files' });
    assert.deepEqual(Object.fromEntries(classroomKindParams(files.toString(), 'quiz')), { subject_code: 'MATH', education_level: '3', unit_id: '8', section: 'quiz' });
    assert.equal(classroomKindParams(files.toString(), 'all').has('section'), false);
});

test('file work opens directly with its task context and closes back to the file list', () => {
    const search = '?subject_code=MATH&education_level=3&unit_id=8&activity_id=50&task_id=9';
    const selected = classroomAssignmentParams(search, '42');
    assert.deepEqual(Object.fromEntries(selected), { subject_code: 'MATH', education_level: '3', unit_id: '8', section: 'worksheet', mode: 'files', assignment_id: '42', task_id: '9' });
    const closed = classroomAssignmentParams(selected.toString(), '');
    assert.equal(closed.has('assignment_id'), false);
    assert.equal(closed.has('task_id'), false);
    assert.equal(closed.get('mode'), 'files');
    for (const id of ['0', '-1', 'other', 'https://example.test']) assert.throws(() => classroomAssignmentParams(search, id));
});

test('ten-row display pages cross API page boundaries without missing or repeating content', () => {
    const source = Array.from({ length: 107 }, (_, id) => id + 1);
    const displayed = [];
    for (let page = 1; page <= 11; page++) {
        const { serverPage, offset, pagination } = classroomContentPage(page, source.length);
        const batch = source.slice((serverPage - 1) * 50, serverPage * 50);
        displayed.push(...batch.slice(offset, offset + 10));
        assert.equal(pagination.current_page, page);
        assert.equal(pagination.last_page, 11);
    }
    assert.deepEqual(displayed, source);
    assert.equal(classroomContentPage(5).serverPage, 1);
    assert.equal(classroomContentPage(6).serverPage, 2);
    assert.equal(classroomContentPage(6).offset, 0);
    assert.equal(classroomContentPage(1, 0).pagination.last_page, 1);
});

test('assigned content navigation carries course, chapter, type and task together from the home screen', () => {
    const task = { id: '9', subject_code: 'สค11001', education_level: 1, unit_id: null };
    const reading = classroomTaskItemParams({ id: '5', kind: 'activity', type: 'knowledge', unit_id: null }, task);
    assert.deepEqual(Object.fromEntries(reading), { subject_code: 'สค11001', education_level: '1', unit_id: 'legacy', task_id: '9', section: 'knowledge', activity_id: '5' });
    const file = classroomTaskItemParams({ id: '42', kind: 'assignment', type: 'worksheet', unit_id: '8' }, task);
    assert.equal(file.get('unit_id'), '8');
    assert.equal(file.get('assignment_id'), '42');
    assert.equal(file.get('mode'), 'files');
    assert.equal(file.has('activity_id'), false);
});
