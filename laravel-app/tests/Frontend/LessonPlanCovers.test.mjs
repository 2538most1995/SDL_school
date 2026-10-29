import test from 'node:test';
import assert from 'node:assert/strict';
import { loadPlanCover } from '../../resources/js/features/learning/lesson-plans/coverSource.ts';

test('stored covers load without downloading a full PDF', async () => {
    const cover = new Blob(['image'], { type: 'image/jpeg' });
    const calls = [];
    const result = await loadPlanCover({ coverPath: '/cover', pdfPath: '/file' }, async path => {
        calls.push(path); return cover;
    }, async () => { throw new Error('must not render'); });
    assert.equal(result, cover);
    assert.deepEqual(calls, ['/cover']);
});

test('missing stored covers recover from the authorized PDF with no external thumbnail service', async () => {
    for (const coverPath of [null, '/cover']) {
        const calls = [];
        const cover = new Blob(['page-one'], { type: 'image/jpeg' });
        const result = await loadPlanCover({ coverPath, pdfPath: '/file' }, async path => {
            calls.push(path);
            if (path === '/cover') throw new Error('404');
            return new Blob(['%PDF-fixture'], { type: 'application/pdf' });
        }, async file => {
            assert.equal(file.name, 'lesson-plan.pdf');
            assert.equal(await file.text(), '%PDF-fixture');
            return { blob: cover };
        });
        assert.equal(result, cover);
        assert.deepEqual(calls, coverPath ? ['/cover', '/file'] : ['/file']);
    }
});

test('links without PDF stay openable and do not pretend to have a generated cover', async () => {
    const unexpected = async () => { throw new Error('unexpected request'); };
    assert.equal(await loadPlanCover({ coverPath: null, pdfPath: null }, unexpected, unexpected), null);
    await assert.rejects(loadPlanCover({ coverPath: '/cover', pdfPath: null }, unexpected, unexpected), /unexpected request/);
});

test('PDF rebuilds are serialized and a failed render cannot block following covers', async () => {
    let active = 0;
    let maximum = 0;
    const render = async file => {
        active++; maximum = Math.max(maximum, active);
        try {
            await new Promise(resolve => setTimeout(resolve, 5));
            if ((await file.text()) === 'broken') throw new Error('broken PDF');
            return { blob: new Blob(['cover']) };
        } finally { active--; }
    };
    const jobs = ['broken', 'valid', 'valid'].map(value => loadPlanCover({ coverPath: null, pdfPath: '/file' }, async () => new Blob([value]), render));
    const results = await Promise.allSettled(jobs);
    assert.deepEqual(results.map(result => result.status), ['rejected', 'fulfilled', 'fulfilled']);
    assert.equal(maximum, 1);
});
