import test from 'node:test';
import assert from 'node:assert/strict';
import { electivesForSave } from '../../resources/js/features/learning/registrationElectives.ts';

const course = { code: 'เลือก101', name: 'วิชาเลือก', credits: 2 };
const row = { ...course, registered: false, transferred: false, remark: '' };

test('untouched elective suggestions do not enter the saved registration or PDF', () => {
    assert.deepEqual(electivesForSave([row], [], [course]), []);
});

test('registration, transfer and remarks make recommended courses part of the saved form', () => {
    for (const selected of [{ ...row, registered: true }, { ...row, transferred: true }, { ...row, remark: 'ตามแผน' }]) {
        assert.deepEqual(electivesForSave([selected], [], [course]), [selected]);
    }
});

test('existing and custom elective rows survive saving even when their checkboxes are cleared', () => {
    const custom = { ...row, code: 'กำหนดเอง', name: 'วิชาใหม่' };
    assert.deepEqual(electivesForSave([row, custom], [course.code], [course]), [row, custom]);
});
