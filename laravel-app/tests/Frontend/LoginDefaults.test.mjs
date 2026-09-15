import assert from 'node:assert/strict';
import test from 'node:test';
import { DEFAULT_LOGIN_TYPE, LOGIN_TYPE_ORDER } from '../../resources/js/lib/loginDefaults.ts';

test('student login is the first and default choice', () => {
    assert.equal(DEFAULT_LOGIN_TYPE, 'student');
    assert.deepEqual(LOGIN_TYPE_ORDER, ['student', 'staff']);
});
