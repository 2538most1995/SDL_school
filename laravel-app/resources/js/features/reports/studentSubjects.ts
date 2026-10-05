export function buildStudentSubjectsPath(studentCode: string, level = ''): string {
    const params = new URLSearchParams();
    if (level.trim()) params.set('level', level.trim());

    return `/api/v1/students/${encodeURIComponent(studentCode)}/subjects${params.size ? `?${params.toString()}` : ''}`;
}
