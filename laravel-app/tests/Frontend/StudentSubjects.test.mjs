import assert from 'node:assert/strict';
import test from 'node:test';
import { buildStudentSubjectsPath } from '../../resources/js/features/reports/studentSubjects.ts';

test('student subject detail keeps education level in the historical lookup', () => {
    assert.equal(
        buildStudentSubjectsPath('6621000099', 'มัธยมศึกษาตอนปลาย'),
        '/api/v1/students/6621000099/subjects?level=%E0%B8%A1%E0%B8%B1%E0%B8%98%E0%B8%A2%E0%B8%A1%E0%B8%A8%E0%B8%B6%E0%B8%81%E0%B8%A9%E0%B8%B2%E0%B8%95%E0%B8%AD%E0%B8%99%E0%B8%9B%E0%B8%A5%E0%B8%B2%E0%B8%A2',
    );
    assert.equal(
        buildStudentSubjectsPath('A/B 01'),
        '/api/v1/students/A%2FB%2001/subjects',
    );
});
