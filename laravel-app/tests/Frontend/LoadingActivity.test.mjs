import assert from 'node:assert/strict';
import test from 'node:test';
import { createLoadingActivity } from '../../resources/js/lib/loadingActivity.ts';

test('overlapping loading indicators remain active until the last owner releases', () => {
    const activity = createLoadingActivity();
    const first = activity.begin();
    const second = activity.begin();
    assert.equal(activity.getSnapshot(), 2);
    first();
    assert.equal(activity.getSnapshot(), 1);
    second();
    assert.equal(activity.getSnapshot(), 0);
});

test('repeated cleanup cannot clear another request or leave the count negative', () => {
    const activity = createLoadingActivity();
    const cancelled = activity.begin();
    const pending = activity.begin();
    cancelled();
    cancelled();
    assert.equal(activity.getSnapshot(), 1);
    pending();
    pending();
    assert.equal(activity.getSnapshot(), 0);
    const remounted = activity.begin();
    assert.equal(activity.getSnapshot(), 1);
    remounted();
    assert.equal(activity.getSnapshot(), 0);
});

test('subscribers receive current counts and stop receiving updates after unmount', () => {
    const activity = createLoadingActivity();
    const snapshots = [];
    const unsubscribe = activity.subscribe(() => snapshots.push(activity.getSnapshot()));
    const finish = activity.begin();
    finish();
    unsubscribe();
    const later = activity.begin();
    later();
    assert.deepEqual(snapshots, [1, 0]);
});
