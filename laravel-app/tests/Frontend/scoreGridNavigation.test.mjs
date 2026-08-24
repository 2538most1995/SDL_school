import assert from 'node:assert/strict';
import test from 'node:test';
import { isScoreGridNavigationKey, nextScoreGridPosition, scoreGridCellKey } from '../../resources/js/features/learning/scoreGridNavigation.ts';

test('left and right arrows move between score columns in the same student row', () => {
    assert.deepEqual(nextScoreGridPosition('ArrowLeft', 1, 2, 3, 4), { row: 1, column: 1 });
    assert.deepEqual(nextScoreGridPosition('ArrowRight', 1, 2, 3, 4), { row: 1, column: 3 });
});

test('up and down arrows move between student rows in the same score column', () => {
    assert.deepEqual(nextScoreGridPosition('ArrowUp', 1, 2, 3, 4), { row: 0, column: 2 });
    assert.deepEqual(nextScoreGridPosition('ArrowDown', 1, 2, 3, 4), { row: 2, column: 2 });
});

test('navigation stays inside the score grid and ignores unrelated keys', () => {
    assert.equal(nextScoreGridPosition('ArrowLeft', 0, 0, 3, 4), null);
    assert.equal(nextScoreGridPosition('ArrowDown', 2, 3, 3, 4), null);
    assert.equal(nextScoreGridPosition('Enter', 1, 1, 3, 4), null);
    assert.equal(isScoreGridNavigationKey('ArrowUp'), true);
    assert.equal(isScoreGridNavigationKey('Enter'), false);
    assert.equal(scoreGridCellKey(2, 3), '2:3');
});
