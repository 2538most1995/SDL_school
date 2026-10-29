export type DocumentPreview = {
    url: string;
    embedUrl: string | null;
    thumbnailUrl: string | null;
    provider: string;
};

export type DocumentOpenTarget =
    | { kind: 'pdf' | 'details' | 'embed' }
    | { kind: 'external'; url: string };

/** Fast cloud links open directly; PDFs open when there is no cloud link. */
export function documentOpenTarget(value: string, hasPdf: boolean): DocumentOpenTarget {
    const preview = documentPreview(value);
    if (preview) {
        return preview.embedUrl ? { kind: 'embed' } : { kind: 'external', url: preview.url };
    }
    if (hasPdf) return { kind: 'pdf' };
    return { kind: 'details' };
}

/**
 * Convert document/cloud links to immediate direct download URLs so clicking
 * the PDF or DOC icon starts downloading directly instead of opening a reader.
 */
export function documentDownloadUrl(value: string, format: 'pdf' | 'word' = 'pdf'): string {
    const source = safeUrl(value);
    if (!source) return value;
    const host = source.hostname.toLowerCase();

    // 1. Google Drive direct download
    if (host === 'drive.google.com') {
        const match = source.pathname.match(/^\/file\/(?:u\/\d+\/)?d\/([A-Za-z0-9_-]+)/);
        const queryId = /^\/(?:open|uc)\/?$/.test(source.pathname) ? source.searchParams.get('id') : null;
        const id = match?.[1] ?? (queryId && fileId.test(queryId) ? queryId : null);
        if (id) {
            const dl = new URL('https://drive.google.com/uc');
            dl.searchParams.set('export', 'download');
            dl.searchParams.set('id', id);
            for (const key of ['resourcekey', 'authuser']) {
                const val = source.searchParams.get(key);
                if (val) dl.searchParams.set(key, val);
            }
            return dl.href;
        }
    }

    // 2. Google Docs / Sheets / Slides export
    if (host === 'docs.google.com') {
        const native = source.pathname.match(/^\/(document|spreadsheets|presentation)\/(?:u\/\d+\/)?d\/([A-Za-z0-9_-]+)/);
        if (native) {
            const kind = native[1];
            const id = native[2];
            const dl = new URL(`/${kind}/d/${id}/export`, source.origin);
            if (kind === 'document') {
                dl.searchParams.set('format', format === 'pdf' ? 'pdf' : 'docx');
            } else if (kind === 'spreadsheets') {
                dl.searchParams.set('format', format === 'pdf' ? 'pdf' : 'xlsx');
            } else if (kind === 'presentation') {
                dl.searchParams.set('format', format === 'pdf' ? 'pdf' : 'pptx');
            }
            for (const key of ['resourcekey', 'authuser']) {
                const val = source.searchParams.get(key);
                if (val) dl.searchParams.set(key, val);
            }
            return dl.href;
        }
    }

    // 3. OneDrive direct download
    if (host === 'onedrive.live.com' || host === 'onedrive.com' || host === '1drv.ms') {
        try {
            const dl = new URL(source.href);
            dl.searchParams.set('download', '1');
            return dl.href;
        } catch {
            return source.href;
        }
    }

    // 4. Dropbox direct download
    if (host === 'dropbox.com' || host === 'www.dropbox.com') {
        try {
            const dl = new URL(source.href);
            dl.searchParams.set('dl', '1');
            return dl.href;
        } catch {
            return source.href;
        }
    }

    return source.href;
}

const fileId = /^[A-Za-z0-9_-]+$/;

function safeUrl(value: string): URL | null {
    // URL() silently strips some controls and repairs backslashes; reject them
    // before parsing, including encoded controls that a destination may decode.
    if (/[\u0000-\u001f\u007f-\u009f\\]/.test(value)
        || /%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)|%c2%(?:8[0-9a-f]|9[0-9a-f])/i.test(value)) return null;
    const trimmed = value.trim();
    if (/\s/u.test(trimmed) || !/^https?:\/\//i.test(trimmed) || /^https?:\/\/[^/?#]*@/i.test(trimmed)) return null;
    try {
        const result = new URL(trimmed);
        return ['https:', 'http:'].includes(result.protocol) && !result.username && !result.password && result.hostname
            ? result : null;
    } catch {
        return null;
    }
}

function googlePreview(source: URL, pathname: string): string {
    const preview = new URL(pathname, source.origin);
    // Resource keys are part of access to link-shared Drive files. Keep them
    // when switching viewer paths without passing editor/export parameters.
    for (const key of ['resourcekey', 'authuser']) {
        const value = source.searchParams.get(key);
        if (value) preview.searchParams.set(key, value);
    }
    return preview.href;
}

function googleThumbnail(source: URL, id: string): string {
    const thumbnail = new URL('https://drive.google.com/thumbnail');
    thumbnail.searchParams.set('id', id);
    thumbnail.searchParams.set('sz', 'w800');
    for (const key of ['resourcekey', 'authuser']) {
        const value = source.searchParams.get(key);
        if (value) thumbnail.searchParams.set(key, value);
    }
    return thumbnail.href;
}

/**
 * Recognize document viewers locally; this function never fetches a URL or
 * changes sharing permissions. A preview can still require provider sign-in.
 * Short sharing links remain openable without guessing an embed or cover URL.
 */
export function documentPreview(value: string): DocumentPreview | null {
    const source = safeUrl(value);
    if (!source) return null;
    const result: DocumentPreview = { url: source.href, embedUrl: null, thumbnailUrl: null, provider: 'เอกสารออนไลน์' };
    const host = source.hostname.toLowerCase();
    const canEmbed = source.protocol === 'https:' && source.port === '';

    if (host === 'drive.google.com') {
        result.provider = 'Google Drive';
        const match = source.pathname.match(/^\/file\/(?:u\/\d+\/)?d\/([A-Za-z0-9_-]+)(?:\/(?:view|preview|edit))?\/?$/);
        const queryId = /^\/(?:open|uc)\/?$/.test(source.pathname) ? source.searchParams.get('id') : null;
        const id = match?.[1] ?? (queryId && fileId.test(queryId) ? queryId : null);
        if (canEmbed && id) {
            result.embedUrl = googlePreview(source, `/file/d/${id}/preview`);
            result.thumbnailUrl = googleThumbnail(source, id);
        }
        return result;
    }

    if (host === 'docs.google.com') {
        const native = source.pathname.match(/^\/(document|spreadsheets|presentation)\/(?:u\/\d+\/)?d\/([A-Za-z0-9_-]+)(?:\/(?:edit|view|preview|embed|present|pub|pubhtml))?\/?$/);
        const published = source.pathname.match(/^\/(document|spreadsheets|presentation)\/d\/e\/([A-Za-z0-9_-]+)\/(pub|pubhtml|embed)\/?$/);
        const kind = native?.[1] ?? published?.[1];
        if (kind) result.provider = { document: 'Google Docs', spreadsheets: 'Google Sheets', presentation: 'Google Slides' }[kind] ?? 'Google Drive';
        else result.provider = 'Google Drive';
        if (canEmbed && native) {
            result.embedUrl = googlePreview(source, `/${native[1]}/d/${native[2]}/preview`);
            result.thumbnailUrl = googleThumbnail(source, native[2]);
            // Sheet/slide anchors select a page without changing access.
            if (/^#(?:gid=\d+|slide=id\.[A-Za-z0-9_-]+)$/.test(source.hash)) result.embedUrl += source.hash;
        } else if (canEmbed && published) {
            // Preserve the publish-to-web URL, including selected sheet/range
            // and publication parameters; no need to re-publish the document.
            result.embedUrl = source.href;
        }
        return result;
    }

    if (host === 'onedrive.live.com' || host === 'onedrive.com' || host === '1drv.ms') {
        result.provider = 'OneDrive';
        // Use the URL supplied by OneDrive's Embed > Generate command. A short
        // /s/ share cannot be resolved to its embed target without a request.
        if (canEmbed && host === 'onedrive.live.com' && /^\/embed\/?$/i.test(source.pathname)
            && (source.searchParams.has('resid') || source.searchParams.has('id'))) result.embedUrl = source.href;
        return result;
    }

    // Exactly one valid tenant label, including tenant-my for OneDrive Business.
    // Do not treat lookalike domains or arbitrary nested subdomains as Microsoft.
    if (/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.sharepoint\.com$/.test(host)) {
        result.provider = 'SharePoint';
        const action = source.searchParams.get('action')?.toLowerCase();
        const layout = /\/_layouts\/15\/(?:doc|wopiframe)\.aspx$/i.test(source.pathname);
        const directFile = /^\/:[a-z]:\/r\//i.test(source.pathname);
        const videoEmbed = /\/_layouts\/15\/embed\.aspx$/i.test(source.pathname);
        if (canEmbed && ((action === 'embedview' && (layout || directFile)) || videoEmbed)) result.embedUrl = source.href;
        return result;
    }

    // Provider thumbnail APIs require file access/OAuth and return expiring
    // links. A guessed public thumbnail would be unreliable for private files.
    return result;
}
