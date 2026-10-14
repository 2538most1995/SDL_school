import assert from 'node:assert/strict';
import test from 'node:test';
import { collectRecordPages, subjectCodesForRecord } from '../../resources/js/features/nnet/recordHelpers.ts';

test('editing imported N-NET results keeps the original level-specific score keys', () => {
    for (const level of [1, 2, 3]) {
        const codes = subjectCodesForRecord(level);
        assert.equal(codes[0], level === 1 ? '111' : level === 2 ? '211' : '411');
        assert.deepEqual(subjectCodesForRecord(level, codes), codes);
    }
    assert.deepEqual(subjectCodesForRecord(2, ['301', '302']), ['301', '302']);
});

test('all-filter export includes records after the first page', async () => {
    const requested = [];
    const records = await collectRecordPages(async (page) => {
        requested.push(page);
        return { items: page === 1 ? [{ id: 1 }, { id: 2 }] : [{ id: 3 }], total: 3, current_page: page, last_page: 2, page_size: 2 };
    });
    assert.deepEqual(requested, [1, 2]);
    assert.deepEqual(records.map((record) => record.id), [1, 2, 3]);
});

test('export refuses changing, duplicated, or incomplete page data', async () => {
    for (const second of [
        { items: [{ id: 3 }], total: 4, current_page: 2, last_page: 2, page_size: 2 },
        { items: [{ id: 2 }], total: 3, current_page: 2, last_page: 2, page_size: 2 },
        { items: [], total: 3, current_page: 2, last_page: 2, page_size: 2 },
    ]) {
        await assert.rejects(collectRecordPages(async (page) => page === 1
            ? { items: [{ id: 1 }, { id: 2 }], total: 3, current_page: 1, last_page: 2, page_size: 2 }
            : second));
    }
});
