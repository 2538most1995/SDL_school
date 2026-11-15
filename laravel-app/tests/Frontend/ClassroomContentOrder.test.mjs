import test from 'node:test';
import assert from 'node:assert/strict';
import { previewContentOrder } from '../../resources/js/features/learning/classroom/contentOrder.ts';

test('pending moves preview several edits without mutating the saved list; cancel restores saved order', () => {
    const saved = [{ id: '1' }, { id: '2' }, { id: '3' }];
    const moves = [{ id: '3', placement: 'first' }, { id: '1', placement: 'after', anchor_id: '2' }];
    assert.deepEqual(previewContentOrder(saved, moves).map(row => row.id), ['3', '2', '1']);
    assert.deepEqual(saved.map(row => row.id), ['1', '2', '3']);
    assert.deepEqual(previewContentOrder(saved, []).map(row => row.id), ['1', '2', '3']);
});

test('partial server pages ignore absent items and anchors rather than dropping rows', () => {
    const rows = [{ id: '1' }, { id: '2' }];
    assert.deepEqual(previewContentOrder(rows, [{ id: '3', placement: 'first' }, { id: '1', placement: 'after', anchor_id: '3' }]), rows);
});
