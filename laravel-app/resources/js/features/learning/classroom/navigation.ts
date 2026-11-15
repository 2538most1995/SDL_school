export function legacyClassroomLocation(kind: 'assignments' | 'resources', search: string): string {
    const old = new URLSearchParams(search);
    const next = new URLSearchParams(kind === 'assignments' ? { section: 'worksheet', mode: 'files' } : { materials: 'all' });
    const id = old.get('assignment_id');
    if (kind === 'assignments' && id && /^[1-9]\d{0,18}$/.test(id)) next.set('assignment_id', id);
    const subject = old.get('subject_code') || old.get('course');
    const level = old.get('education_level');
    if (subject && subject !== 'ทั้งหมด' && subject.length <= 32) next.set('subject_code', subject);
    if (level && /^[123]$/.test(level)) next.set('education_level', level);
    return `/learning/classroom?${next}`;
}

export function classroomUnitParams(search: string, unitId: string): URLSearchParams {
    if (unitId && !/^(legacy|[1-9]\d*)$/.test(unitId)) throw new Error('Invalid classroom activity');
    const next = new URLSearchParams(search);
    for (const key of ['activity_id', 'assignment_id', 'task_id', 'section', 'mode', 'materials', 'tab']) next.delete(key);
    if (unitId) next.set('unit_id', unitId); else next.delete('unit_id');
    return next;
}

export type ClassroomTab = 'lessons' | 'tasks' | 'documents' | 'library' | 'templates';

/** Keep the existing 50-record API contract while rendering ten lesson rows at a time. */
export function classroomContentPage(page: number, total = 0) {
    const current = Math.max(1, Math.trunc(page));
    return { serverPage: Math.ceil(current / 5), offset: ((current - 1) % 5) * 10, pagination: { current_page: current, last_page: Math.max(1, Math.ceil(total / 10)), total } };
}

export function classroomTabParams(search: string, tab: ClassroomTab): URLSearchParams {
    const next = new URLSearchParams(search);
    for (const key of ['activity_id', 'assignment_id', 'task_id', 'section', 'mode', 'materials', 'tab']) next.delete(key);
    if (tab === 'library') next.set('materials', 'all');
    else if (tab !== 'lessons') next.set('tab', tab);
    return next;
}

export function classroomKindParams(search: string, kind: 'all' | 'video' | 'knowledge' | 'worksheet' | 'quiz', mode: 'online' | 'files' = 'online'): URLSearchParams {
    const next = classroomTabParams(search, 'lessons');
    if (kind !== 'all') next.set('section', kind);
    if (kind === 'worksheet' && mode === 'files') next.set('mode', mode);
    return next;
}

export function classroomAssignmentParams(search: string, id: string): URLSearchParams {
    if (id && !/^[1-9]\d{0,18}$/.test(id)) throw new Error('Invalid assignment');
    const next = classroomKindParams(search, 'worksheet', 'files');
    const taskId = new URLSearchParams(search).get('task_id');
    if (id) {
        next.set('assignment_id', id);
        if (taskId) next.set('task_id', taskId);
    }
    return next;
}

export function classroomTaskItemParams(
    item: { id: string; kind: 'activity' | 'assignment'; type: 'video' | 'knowledge' | 'worksheet' | 'quiz'; unit_id?: string | null },
    task: { id: string; subject_code: string; education_level: number; unit_id?: string | null },
): URLSearchParams {
    const next = new URLSearchParams({ subject_code: task.subject_code, education_level: String(task.education_level), unit_id: item.unit_id ?? task.unit_id ?? 'legacy', task_id: task.id });
    next.set('section', item.kind === 'assignment' ? 'worksheet' : item.type);
    if (item.kind === 'assignment') { next.set('assignment_id', item.id); next.set('mode', 'files'); }
    else next.set('activity_id', item.id);
    return next;
}
