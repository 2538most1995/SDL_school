import test from 'node:test';
import assert from 'node:assert/strict';
import { safeClassroomUrl, youtubeEmbed } from '../../resources/js/features/learning/classroom/types.ts';

test('classroom accepts shared cloud links and rejects executable or credentialed URLs', () => {
    for (const url of ['https://drive.google.com/file/d/test/view', 'https://1drv.ms/u/s!test', 'https://school.sharepoint.com/:b:/test']) assert.ok(safeClassroomUrl(url));
    for (const url of ['javascript:alert(1)', 'data:text/html,evil', '//evil.test', 'https://user:secret@example.test', 'https://example.test/\npath', 'https:\\evil.test']) assert.equal(safeClassroomUrl(url), null);
});

test('YouTube player only accepts known hosts and exact video IDs', () => {
    const embed = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
    for (const url of ['https://youtu.be/dQw4w9WgXcQ?t=1', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=test', 'https://youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ']) assert.equal(youtubeEmbed(url), embed);
    for (const url of ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'https://youtube.com/watch?v=bad', 'javascript:alert(1)', 'https://drive.google.com/file/d/test']) assert.equal(youtubeEmbed(url), null);
});
