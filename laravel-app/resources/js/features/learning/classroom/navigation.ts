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
    for (const key of ['activity_id', 'assignment_id', 'task_id', 'section', 'mode', 'materials']) next.delete(key);
    if (unitId) next.set('unit_id', unitId); else next.delete('unit_id');
    return next;
}
