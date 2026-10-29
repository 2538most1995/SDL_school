/** Use the authenticated native PDF endpoint, never a full-file Blob download. */
export function lessonPlanFilePath(id: string, currentFile: string, district: string, download = false): string {
    const params = new URLSearchParams();
    const version = new URL(currentFile, 'https://local.invalid').searchParams.get('v');
    if (version) params.set('v', version);
    if (district) params.set('district_id', district);
    if (download) params.set('download', '1');
    return `/api/v1/learning/lesson-plans/${encodeURIComponent(id)}/file${params.size ? `?${params}` : ''}`;
}

/** Use the authenticated native Word endpoint to download doc/docx files directly. */
export function lessonPlanWordPath(id: string, currentFile: string, district: string): string {
    const params = new URLSearchParams();
    const version = new URL(currentFile, 'https://local.invalid').searchParams.get('v');
    if (version) params.set('v', version);
    if (district) params.set('district_id', district);
    return `/api/v1/learning/lesson-plans/${encodeURIComponent(id)}/word${params.size ? `?${params}` : ''}`;
}
