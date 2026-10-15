import assert from 'node:assert/strict';
import test from 'node:test';
import { ImportedScoreAutosave, scoreFields, scoreValues } from '../../resources/js/features/learning/importedScoreAutosave.ts';

const blank = () => Array(11).fill('');
const initial = () => ({ a: { fields: blank(), token: 'original-a' }, b: { fields: blank(), token: 'original-b' } });
const pause = () => new Promise(resolve => setTimeout(resolve, 0));

test('empty marks stay null, real zero stays zero, and totals require both marks', () => {
    assert.equal(scoreValues(blank()).total_score, null);
    const fields = blank(); fields[0] = '0'; fields[9] = '0'; fields[10] = '30';
    assert.equal(scoreValues(fields).assessment_scores[0], 0);
    assert.equal(scoreValues(fields).total_score, 30);
    assert.throws(() => scoreValues([...Array(9).fill(''), '80', '30']));
    assert.throws(() => scoreValues([...Array(10).fill(''), 'oops']));
    assert.equal(scoreFields({ assessment_scores: [0], midterm_score: null, final_exam_score: 20 })[0], '0');
});

test('typing is debounced and finishing flushes every dirty row without saving untouched marks', async () => {
    const sent = [];
    const queue = new ImportedScoreAutosave(initial(), async (key, fields, token) => {
        sent.push({ key, fields, token }); return { edit_token: `${key}-saved` };
    }, 60_000);
    queue.set('a', 0, '1'); queue.set('a', 0, '12'); queue.set('a', 1, '0');
    assert.equal(sent.length, 0);
    assert.equal(queue.getSnapshot().a.fields[9], '12');
    assert.equal(await queue.flush(), true);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].fields[0], '12');
    assert.equal(queue.getSnapshot().b.version, 0);
    assert.equal(queue.getSnapshot().a.status, 'saved');
    queue.stop();
});

test('edits during an in-flight save keep the newest fields and chain the server token in order', async () => {
    let resolveFirst;
    const sent = [];
    const queue = new ImportedScoreAutosave(initial(), async (key, fields, token) => {
        sent.push({ key, fields, token });
        if (sent.length === 1) return new Promise(resolve => { resolveFirst = resolve; });
        return { edit_token: `saved-${sent.length}` };
    }, 60_000);
    queue.set('a', 0, '10');
    const flushed = queue.flush();
    await pause();
    queue.set('a', 0, '18'); queue.set('b', 10, '0');
    assert.equal(sent.length, 1);
    resolveFirst({ edit_token: 'a-after-first' });
    assert.equal(await flushed, true);
    assert.equal(sent.length, 3);
    assert.equal(sent[1].token, 'a-after-first');
    assert.equal(sent[1].fields[0], '18');
    assert.equal(queue.getSnapshot().a.fields[0], '18');
    assert.equal(queue.getSnapshot().b.fields[10], '0');
    queue.stop();
});

test('failed marks remain visible and block finishing until corrected, without retry storms', async () => {
    let requests = 0;
    const queue = new ImportedScoreAutosave(initial(), async () => {
        requests++;
        if (requests === 1) throw new Error('DBF requires integers');
        return { edit_token: 'corrected' };
    }, 60_000);
    queue.set('a', 0, '17.5');
    assert.equal(await queue.flush(), false);
    assert.equal(requests, 1);
    assert.equal(queue.getSnapshot().a.fields[0], '17.5');
    assert.equal(queue.getSnapshot().a.status, 'error');
    assert.equal(await queue.flush(), false);
    assert.equal(requests, 1);
    queue.set('a', 0, '18');
    assert.equal(await queue.flush(), true);
    assert.equal(requests, 2);
    queue.stop();
});

test('a stale row cannot silently retry with another writers token; explicit reload clears the conflict', async () => {
    let requests = 0;
    const queue = new ImportedScoreAutosave(initial(), async () => {
        requests++; throw Object.assign(new Error('คะแนนเปลี่ยนแล้ว'), { status: 409 });
    }, 60_000);
    queue.set('a', 10, '20');
    assert.equal(await queue.flush(), false);
    queue.set('a', 10, '21'); queue.retry('a');
    assert.equal(await queue.flush(), false);
    assert.equal(requests, 1);
    assert.equal(queue.getSnapshot().a.fields[10], '21');
    queue.replace('a', blank(), 'fresh');
    assert.equal(queue.hasUnsaved(), false);
    queue.stop();
});

test('autosave runs after a pause without pressing finish', async () => {
    const sent = [];
    let saved;
    const observed = new Promise(resolve => { saved = resolve; });
    const queue = new ImportedScoreAutosave(initial(), async (key) => {
        sent.push(key); saved(); return { edit_token: 'autosaved' };
    }, 5);
    queue.set('a', 0, '20');
    await observed;
    await queue.flush();
    assert.equal(sent[0], 'a');
    assert.equal(queue.hasUnsaved(), false);
    queue.stop();
});

test('a completion conflict can be resolved without losing another learner draft', async () => {
    const queue = new ImportedScoreAutosave(initial(), async () => ({ edit_token: 'saved' }), 60_000);
    queue.set('b', 10, '30');
    queue.markConflict('a', 'another teacher finished this row');
    queue.remove('a');
    assert.equal(queue.getSnapshot().a, undefined);
    assert.equal(queue.getSnapshot().b.fields[10], '30');
    assert.equal(await queue.flush(), true);
    queue.stop();
});
