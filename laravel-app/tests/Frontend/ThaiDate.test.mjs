import assert from 'node:assert/strict';
import test from 'node:test';
import { formatThaiDisplayDate } from '../../resources/js/lib/thaiDate.ts';

test('student dates use Thai abbreviated months and Buddhist years without double conversion', () => {
    for (const value of ['20/05/2549', '20/05/2006', '2006-05-20', '2549-05-20', '20060520', '๒๐/๐๕/๒๕๔๙', '20 พฤษภาคม 2549', '20 พ.ค. 2549']) {
        assert.equal(formatThaiDisplayDate(value), '20 พ.ค. 2549');
    }
});

test('civil date formatting does not shift the day based on the machine timezone', () => {
    assert.equal(formatThaiDisplayDate('2026-10-19T23:30:00-08:00'), '19 ต.ค. 2569');
    assert.equal(formatThaiDisplayDate('2026-10-19 00:00:00'), '19 ต.ค. 2569');
});

test('invalid and empty student dates remain missing while leap days are validated as Gregorian dates', () => {
    for (const value of ['', null, undefined, '-', '00000000', '0000-00-00', '31/02/2549', '29/02/2566', '01/13/2569', '99 พ.ค. 2549']) {
        assert.equal(formatThaiDisplayDate(value), '-');
    }
    assert.equal(formatThaiDisplayDate('29/02/2567'), '29 ก.พ. 2567');
    assert.equal(formatThaiDisplayDate('1/1/2569'), '1 ม.ค. 2569');
});
