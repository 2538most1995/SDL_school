import test from 'node:test';
import assert from 'node:assert/strict';
import { documentOpenTarget, documentPreview } from '../../resources/js/features/learning/lesson-plans/documentLinks.ts';

test('link-only OneDrive and generic documents open directly without a second reader click', () => {
    for (const url of ['https://1drv.ms/b/c/example/IQC_Demo-Plan?e=demo', 'https://school.example.org/plan.pdf']) {
        assert.deepEqual(documentOpenTarget(url, false), { kind: 'external', url });
    }
    assert.deepEqual(documentOpenTarget('https://drive.google.com/file/d/FILE123/view', false), { kind: 'embed' });
    assert.deepEqual(documentOpenTarget('javascript:alert(1)', false), { kind: 'details' });
    assert.deepEqual(documentOpenTarget('', false), { kind: 'details' });
});

test('attached PDFs open immediately even when a cloud link is also present', () => {
    for (const url of ['', 'https://1drv.ms/b/s!demo', 'https://drive.google.com/file/d/FILE123/view']) {
        assert.deepEqual(documentOpenTarget(url, true), { kind: 'pdf' });
    }
});

test('Drive file and query sharing routes preview the same file and retain resource keys', () => {
    for (const url of [
        'https://drive.google.com/file/d/file_A-123/view?usp=sharing&resourcekey=key_456',
        'https://drive.google.com/open?id=file_A-123&resourcekey=key_456',
        'https://drive.google.com/uc?export=download&id=file_A-123&resourcekey=key_456',
        'https://drive.google.com/file/u/0/d/file_A-123/preview?resourcekey=key_456',
    ]) {
        const result = documentPreview(url);
        assert.equal(result?.url, url);
        assert.equal(result?.embedUrl, 'https://drive.google.com/file/d/file_A-123/preview?resourcekey=key_456');
        assert.equal(result?.provider, 'Google Drive');
        assert.equal(result?.thumbnailUrl, null);
    }
    assert.equal(documentPreview('https://drive.google.com/drive/folders/folder_123')?.embedUrl, null);
    assert.equal(documentPreview('https://drive.google.com/open?id=bad%2Fid')?.embedUrl, null);
});

test('native Google files use preview paths and published documents keep their publishing options', () => {
    const cases = [
        ['document', 'Google Docs', ''],
        ['spreadsheets', 'Google Sheets', '#gid=42'],
        ['presentation', 'Google Slides', '#slide=id.page_1'],
    ];
    for (const [kind, provider, hash] of cases) {
        const result = documentPreview(`https://docs.google.com/${kind}/d/FILE123/edit?usp=sharing${hash}`);
        assert.equal(result?.provider, provider);
        assert.equal(result?.embedUrl, `https://docs.google.com/${kind}/d/FILE123/preview${hash}`);
    }
    const published = 'https://docs.google.com/spreadsheets/d/e/PUBLISHED123/pubhtml?gid=15&single=true&widget=true&headers=false';
    assert.equal(documentPreview(published)?.embedUrl, published);
    assert.equal(documentPreview('https://docs.google.com/document/d/e/FILE123/pub?embedded=true')?.embedUrl, 'https://docs.google.com/document/d/e/FILE123/pub?embedded=true');
});

test('OneDrive generated embed preserves all tokens without inventing an anonymous thumbnail API', () => {
    const url = 'https://onedrive.live.com/embed?resid=ABC%21123&authkey=%21SECRET&em=2';
    assert.deepEqual(documentPreview(url), { url, embedUrl: url, thumbnailUrl: null, provider: 'OneDrive' });
    for (const share of ['https://1drv.ms/b/s!AbCdEf?e=secret', 'https://onedrive.live.com/?cid=ABC&id=ABC%21123&authkey=SECRET', 'https://onedrive.com/share/ABC']) {
        assert.equal(documentPreview(share)?.url, share);
        assert.equal(documentPreview(share)?.provider, 'OneDrive');
        assert.equal(documentPreview(share)?.embedUrl, null);
        assert.equal(documentPreview(share)?.thumbnailUrl, null);
    }
    assert.equal(documentPreview('https://onedrive.live.com/embed')?.embedUrl, null);
});

test('SharePoint only embeds recognized layouts or direct embed views on a valid tenant', () => {
    const urls = [
        'https://school.sharepoint.com/sites/lessons/_layouts/15/Doc.aspx?sourcedoc=%7BGUID%7D&action=embedview&authkey=secret',
        'https://school-my.sharepoint.com/personal/teacher/_layouts/15/WopiFrame.aspx?sourcedoc=%7BGUID%7D&action=embedview',
        'https://school.sharepoint.com/:x:/r/sites/class/lesson.xlsx?e=TOKEN&action=embedview',
        'https://school.sharepoint.com/sites/class/_layouts/15/embed.aspx?UniqueId=GUID&token=secret',
    ];
    for (const url of urls) {
        assert.equal(documentPreview(url)?.provider, 'SharePoint');
        assert.equal(documentPreview(url)?.embedUrl, url);
    }
    for (const url of [
        'https://school.sharepoint.com/sites/lessons/_layouts/15/Doc.aspx?sourcedoc=GUID&action=edit',
        'https://school.sharepoint.com/:w:/s/Class/SOMESHARE?e=TOKEN',
        'https://school.sharepoint.com/other-page?action=embedview',
    ]) assert.equal(documentPreview(url)?.embedUrl, null);
});

test('safe external links and HTTP providers stay openable without arbitrary iframe navigation', () => {
    const url = 'https://example.org/lesson.pdf?view=1';
    assert.deepEqual(documentPreview(url), { url, embedUrl: null, thumbnailUrl: null, provider: 'เอกสารออนไลน์' });
    assert.equal(documentPreview('http://drive.google.com/file/d/FILE123/view')?.embedUrl, null);
    assert.equal(documentPreview('https://drive.google.com:8443/file/d/FILE123/view')?.embedUrl, null);
    assert.equal(documentPreview('  https://example.org/doc  ')?.url, 'https://example.org/doc');
    const thaiPath = 'https://example.org/%E0%B9%81%E0%B8%9C%E0%B8%99.pdf';
    assert.equal(documentPreview(thaiPath)?.url, thaiPath);
});

test('lookalike and nested hosts never gain trusted provider/embed status', () => {
    for (const url of [
        'https://drive.google.com.evil.test/file/d/FILE123/view',
        'https://evil-drive.google.com/file/d/FILE123/view',
        'https://docs.google.com.evil.test/document/d/FILE123/edit',
        'https://onedrive.live.com.evil.test/embed?resid=1',
        'https://school.sharepoint.com.evil.test/_layouts/15/Doc.aspx?action=embedview',
        'https://nested.school.sharepoint.com/_layouts/15/Doc.aspx?action=embedview',
        'https://-school.sharepoint.com/_layouts/15/Doc.aspx?action=embedview',
        'https://school-.sharepoint.com/_layouts/15/Doc.aspx?action=embedview',
    ]) {
        assert.equal(documentPreview(url)?.embedUrl, null);
        assert.equal(documentPreview(url)?.provider, 'เอกสารออนไลน์');
    }
});

test('invalid schemes, userinfo, control characters and backslashes are rejected before URL repair', () => {
    for (const value of [
        '', 'https://example.org/my plan.pdf', '/relative.pdf', '//drive.google.com/file/d/FILE123/view', 'https:example.org',
        'javascript:alert(1)', 'data:text/html,test', 'file:///private/test.pdf',
        'https://user:password@drive.google.com/file/d/FILE123/view',
        'https://@drive.google.com/file/d/FILE123/view',
        'https://drive.google.com\\@evil.test/file/d/FILE123/view',
        'https://drive.google.com/file/d/FILE123/vi\new',
        '\thttps://drive.google.com/file/d/FILE123/view',
        'https://example.org/doc%0Aname', 'https://example.org/doc%5cname', 'https://example.org/doc%C2%80name',
    ]) assert.equal(documentPreview(value), null, value);
});
